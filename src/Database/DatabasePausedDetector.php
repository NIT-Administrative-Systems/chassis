<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Database;

use Generator;
use Illuminate\View\ViewException;
use PDOException;
use Throwable;

/**
 * Detects Aurora Serverless v2 scale-to-zero wake failures.
 *
 * When Aurora is paused, the first connection request resumes the instance. If
 * the PHP/PostgreSQL client times out before Aurora is accepting connections,
 * PDO surfaces a PostgreSQL connection failure (`SQLSTATE 08006`, driver code
 * `7`) with timeout-oriented wording. Laravel can then wrap that PDO failure in
 * an `Illuminate\Database\QueryException` and, if it happens while Blade is
 * rendering, one or more `Illuminate\View\ViewException` instances.
 *
 * This detector follows the exception chain and prefers stable database signals
 * from PDO metadata before falling back to the wrapper messages Laravel adds
 * around view-rendering failures.
 *
 * @see https://docs.aws.amazon.com/AmazonRDS/latest/AuroraUserGuide/aurora-serverless-v2-auto-pause.html
 * @see https://www.postgresql.org/docs/current/errcodes-appendix.html
 * @see \Illuminate\Database\QueryException
 * @see ViewException
 */
final readonly class DatabasePausedDetector
{
    private const string POSTGRES_CONNECTION_FAILURE_SQLSTATE = '08006';

    private const int POSTGRES_CONNECTION_FAILURE_DRIVER_CODE = 7;

    /**
     * Phrases emitted by libpq/PDO when the client gives up while opening the
     * connection. Keep this intentionally narrower than Laravel's generic lost
     * connection detector so we do not treat every database outage as an Aurora
     * auto-pause wake-up.
     *
     * @var list<string>
     */
    private const array CONNECTION_TIMEOUT_PHRASES = [
        'timeout expired',
        'connection timed out',
        'operation timed out',
        'ssl: operation timed out',
        'ssl: handshake timed out',
        'did not properly respond after a period of time',
    ];

    /**
     * @var list<string>
     */
    private const array DATABASE_CONTEXT_PHRASES = [
        'SQLSTATE[08006]',
        'Connection: pgsql',
        'connection to server',
        'failed to connect to',
        'pgsql:',
    ];

    /**
     * Determine whether the exception, or any exception it wraps, represents a
     * PostgreSQL connection timeout consistent with an Aurora Serverless v2
     * instance waking from zero ACUs.
     */
    public function causedByPausedDatabase(Throwable $exception): bool
    {
        foreach ($this->exceptionChain($exception) as $candidate) {
            if ($this->isPausedDatabaseException($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Classify a single exception in the chain.
     *
     * PDO exceptions can expose stable SQLSTATE and driver codes. View
     * exceptions usually cannot, so they are treated as wrappers and must carry
     * both timeout language and database connection context in their message.
     */
    private function isPausedDatabaseException(Throwable $exception): bool
    {
        $diagnosticText = $this->diagnosticText($exception);
        $hasConnectionTimeout = $this->containsAny($diagnosticText, self::CONNECTION_TIMEOUT_PHRASES);
        $hasDatabaseContext = $this->containsAny($diagnosticText, self::DATABASE_CONTEXT_PHRASES);

        if (! $hasConnectionTimeout) {
            return false;
        }

        if ($exception instanceof PDOException) {
            return $this->pdoExceptionIndicatesPostgresConnectionFailure($exception, $hasDatabaseContext)
                || $hasDatabaseContext;
        }

        return $exception instanceof ViewException && $hasDatabaseContext;
    }

    /**
     * Walk the exception chain Laravel builds as it wraps lower-level database
     * failures in query and view exceptions.
     *
     * @return Generator<int, Throwable>
     */
    private function exceptionChain(Throwable $exception): Generator
    {
        do {
            yield $exception;

            $exception = $exception->getPrevious();
        } while ($exception instanceof Throwable);
    }

    /**
     * Read PDO's structured error metadata before relying on localized or
     * driver-specific exception messages.
     */
    private function pdoExceptionIndicatesPostgresConnectionFailure(PDOException $exception, bool $hasDatabaseContext): bool
    {
        if ($this->sqlState($exception) === self::POSTGRES_CONNECTION_FAILURE_SQLSTATE) {
            return true;
        }

        return $hasDatabaseContext
            && $this->driverCode($exception) === self::POSTGRES_CONNECTION_FAILURE_DRIVER_CODE;
    }

    /**
     * PDO stores SQLSTATE either as the exception code or as the first
     * `errorInfo` element depending on where the failure was raised.
     */
    private function sqlState(PDOException $exception): ?string
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();

        return is_string($sqlState) && $sqlState !== '' ? $sqlState : null;
    }

    /**
     * PostgreSQL's PDO driver commonly reports libpq's connection failure code
     * as an integer, but some drivers expose numeric values as strings.
     */
    private function driverCode(PDOException $exception): ?int
    {
        $driverCode = $exception->errorInfo[1] ?? null;

        if (is_int($driverCode)) {
            return $driverCode;
        }

        return is_string($driverCode) && ctype_digit($driverCode)
            ? (int) $driverCode
            : null;
    }

    /**
     * Combine the primary exception message with PDO's driver message so sparse
     * PDO exceptions still expose enough text for timeout/context matching.
     */
    private function diagnosticText(Throwable $exception): string
    {
        $text = $exception->getMessage();

        if ($exception instanceof PDOException && is_string($exception->errorInfo[2] ?? null)) {
            $text .= ' ' . $exception->errorInfo[2];
        }

        return $text;
    }

    /**
     * Case-insensitive containment helper for driver messages that do not have
     * a stable structured field.
     *
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        $normalizedHaystack = strtolower($haystack);

        foreach ($needles as $needle) {
            if (str_contains($normalizedHaystack, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
