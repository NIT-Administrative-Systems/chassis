<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Lottery;
use Northwestern\SysDev\Chassis\ValueObjects\ApiRequestContext;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Captures and persists metadata for API requests.
 *
 * Records request/response details for authenticated API traffic
 * and any request that produced a failure reason. Runs after the
 * authentication middleware so the log can include the resolved
 * user, token ID, status code, duration, and failure reason.
 *
 * Successful requests may be sampled; failures are always logged.
 *
 * Extend this class and implement the abstract methods to wire up
 * your application's log persistence and config paths.
 *
 * ```php
 * class LogsApiRequests extends \Northwestern\SysDev\Chassis\Http\Middleware\LogsApiRequests
 * {
 *     protected function isEnabled(): bool
 *     {
 *         return (bool) config('api.request_logging.enabled');
 *     }
 *
 *     protected function isSamplingEnabled(): bool
 *     {
 *         return (bool) config('api.request_logging.sampling.enabled');
 *     }
 *
 *     protected function sampleRate(): float
 *     {
 *         return (float) config('api.request_logging.sampling.rate', 1.0);
 *     }
 *
 *     protected function persistLog(array $data): void
 *     {
 *         ApiRequestLog::create($data);
 *     }
 * }
 * ```
 */
abstract class LogsApiRequests
{
    protected const string TRACE_HEADER = 'X-Trace-Id';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isEnabled()) {
            return $next($request);
        }

        $startTime = microtime(true);

        $response = $next($request);

        $endTime = microtime(true);
        $durationMs = (int) (($endTime - $startTime) * 1000);

        $traceId = Context::get(ApiRequestContext::TRACE_ID);
        if (is_string($traceId)) {
            $response->headers->set(static::TRACE_HEADER, $traceId);
        }

        $userId = Context::get(ApiRequestContext::USER_ID);
        $failureReason = Context::get(ApiRequestContext::FAILURE_REASON);
        $statusCode = $response->getStatusCode();

        // Skip logging completely unauthenticated requests without a failure reason
        if ($userId === null && $failureReason === null) {
            return $response;
        }

        /** @var int<100, 599> $clampedStatus */
        $clampedStatus = min(599, max(100, $statusCode));

        if (! $this->shouldLogRequest($clampedStatus, is_string($failureReason) ? $failureReason : null)) {
            return $response;
        }

        try {
            $responseBytes = null;
            if (! $response instanceof StreamedResponse) {
                /** @var numeric-string|null $contentLength */
                $contentLength = $response->headers->get('Content-Length');

                $responseBytes = $contentLength !== null
                    ? (int) $contentLength
                    : strlen((string) $response->getContent());
            }

            $requestBytes = (int) $request->header('Content-Length', '0');

            $this->persistLog([
                'trace_id' => $traceId,
                'user_id' => $userId,
                'token_id' => Context::get(ApiRequestContext::TOKEN_ID),
                'method' => $request->method(),
                'path' => $request->path(),
                'route_name' => $request->route()?->getName(),
                'ip_address' => $request->ip() ?? 'unknown',
                'status_code' => $statusCode,
                'duration_ms' => $durationMs,
                'request_bytes' => $requestBytes > 0 ? $requestBytes : null,
                'response_bytes' => $responseBytes,
                'user_agent' => $request->userAgent(),
                'failure_reason' => $failureReason,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return $response;
    }

    /**
     * Whether request logging is enabled.
     */
    abstract protected function isEnabled(): bool;

    /**
     * Whether probabilistic sampling is enabled for successful requests.
     */
    abstract protected function isSamplingEnabled(): bool;

    /**
     * The sampling rate for successful requests (0.0 to 1.0).
     */
    abstract protected function sampleRate(): float;

    /**
     * Persist a log entry.
     *
     * The `$data` array contains standardized keys. Map them to your
     * application's log model columns. The `token_id` key may need to
     * be renamed (e.g. `access_token_id`, `user_api_token_id`).
     *
     * @param  array<string, mixed>  $data
     */
    abstract protected function persistLog(array $data): void;

    /**
     * Determine if the current request should be logged.
     *
     * Errors and failures are always logged. Successful requests
     * are subject to probabilistic sampling.
     *
     * @param  int<100, 599>  $statusCode
     */
    protected function shouldLogRequest(int $statusCode, ?string $failureReason): bool
    {
        if (! $this->isSamplingEnabled()) {
            return true;
        }

        // Always log errors and failures
        if ($statusCode >= 400 || $failureReason !== null) {
            return true;
        }

        $sampleRate = max(0.0, min(1.0, $this->sampleRate()));

        if ($sampleRate <= 0.0) {
            return false;
        }

        if ($sampleRate >= 1.0) {
            return true;
        }

        $numerator = (int) round($sampleRate * 100);

        /** @var bool $result */
        $result = Lottery::odds($numerator, 100)
            ->winner(static fn (): bool => true)
            ->loser(static fn (): bool => false)
            ->choose();

        return $result;
    }
}
