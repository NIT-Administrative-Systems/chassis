<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Unit\Database;

use Illuminate\Database\QueryException;
use Illuminate\View\ViewException;
use Northwestern\SysDev\Chassis\Database\DatabasePausedDetector;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Throwable;

#[CoversClass(DatabasePausedDetector::class)]
class DatabasePausedDetectorTest extends TestCase
{
    public function test_detects_raw_pdo_connection_timeout_with_sqlstate_metadata(): void
    {
        $exception = $this->pdoConnectionTimeout();

        $this->assertTrue($this->detector()->causedByPausedDatabase($exception));
    }

    public function test_detects_query_exception_wrapping_pdo_connection_timeout(): void
    {
        $exception = $this->queryException($this->pdoConnectionTimeout());

        $this->assertTrue($this->detector()->causedByPausedDatabase($exception));
    }

    public function test_detects_view_exception_wrapping_query_exception_from_blade_rendering(): void
    {
        $queryException = $this->queryException($this->pdoConnectionTimeout());
        $exception = $this->viewException($queryException);

        $this->assertTrue($this->detector()->causedByPausedDatabase($exception));
    }

    public function test_detects_repeated_view_exception_wrappers_from_nested_layouts(): void
    {
        $queryException = $this->queryException($this->pdoConnectionTimeout());
        $innerViewException = $this->viewException($queryException);
        $outerViewException = $this->viewException($innerViewException);

        $this->assertTrue($this->detector()->causedByPausedDatabase($outerViewException));
    }

    public function test_detects_view_exception_when_wrapper_message_preserves_database_context(): void
    {
        $exception = new ViewException(
            'SQLSTATE[08006] [7] connection to server at "example.cluster.amazonaws.com", port 5432 failed: timeout expired (Connection: pgsql) (View: /var/task/vendor/northwestern-sysdev/northwestern-laravel-ui/resources/views/purple-chrome.blade.php)',
            0,
            1,
            __FILE__,
            __LINE__,
        );

        $this->assertTrue($this->detector()->causedByPausedDatabase($exception));
    }

    public function test_detects_postgres_operation_timed_out_connection_failure(): void
    {
        $exception = $this->pdoConnectionTimeout('SQLSTATE[08006] [7] connection to server failed: Operation timed out');

        $this->assertTrue($this->detector()->causedByPausedDatabase($exception));
    }

    public function test_detects_timeout_from_pdo_error_info_when_exception_message_is_sparse(): void
    {
        $exception = new PDOException('SQLSTATE[08006]');
        $exception->errorInfo = ['08006', '7', 'connection to server failed: timeout expired'];

        $this->assertTrue($this->detector()->causedByPausedDatabase($exception));
    }

    public function test_detects_driver_code_fallback_when_sqlstate_is_unavailable(): void
    {
        $exception = new PDOException('connection to server failed: timeout expired');
        $exception->errorInfo = [null, '7', 'connection to server failed: timeout expired'];

        $this->assertTrue($this->detector()->causedByPausedDatabase($exception));
    }

    public function test_ignores_non_database_timeout_exceptions(): void
    {
        $exception = new RuntimeException('The upstream API request timeout expired.');

        $this->assertFalse($this->detector()->causedByPausedDatabase($exception));
    }

    public function test_ignores_view_exception_without_database_context(): void
    {
        $exception = new ViewException(
            'The upstream API request timeout expired. (View: /var/task/resources/views/errors/404.blade.php)',
            0,
            1,
            __FILE__,
            __LINE__,
        );

        $this->assertFalse($this->detector()->causedByPausedDatabase($exception));
    }

    public function test_ignores_postgres_connection_failure_without_timeout_signal(): void
    {
        $exception = new PDOException('SQLSTATE[08006] [7] could not connect to server: Connection refused');
        $exception->errorInfo = ['08006', 7, 'could not connect to server: Connection refused'];

        $this->assertFalse($this->detector()->causedByPausedDatabase($exception));
    }

    public function test_ignores_database_statement_timeout(): void
    {
        $exception = new QueryException(
            'pgsql',
            'select pg_sleep(30)',
            [],
            new PDOException('SQLSTATE[57014]: Query canceled: 7 ERROR: canceling statement due to statement timeout'),
        );

        $this->assertFalse($this->detector()->causedByPausedDatabase($exception));
    }

    private function detector(): DatabasePausedDetector
    {
        return new DatabasePausedDetector();
    }

    private function pdoConnectionTimeout(
        string $message = 'SQLSTATE[08006] [7] connection to server failed: timeout expired',
    ): PDOException {
        $exception = new PDOException($message);
        $exception->errorInfo = ['08006', 7, $message];

        return $exception;
    }

    private function queryException(PDOException $previous): QueryException
    {
        return new QueryException(
            'pgsql',
            'select count(*) as aggregate from "platform_announcements"',
            [],
            $previous,
            ['Host' => 'example-aurora-cluster.cluster-example.us-east-2.rds.amazonaws.com'],
        );
    }

    private function viewException(Throwable $previous): ViewException
    {
        return new ViewException(
            $previous->getMessage() . ' (View: /var/task/vendor/northwestern-sysdev/northwestern-laravel-ui/resources/views/purple-chrome.blade.php)',
            0,
            1,
            __FILE__,
            __LINE__,
            $previous,
        );
    }
}
