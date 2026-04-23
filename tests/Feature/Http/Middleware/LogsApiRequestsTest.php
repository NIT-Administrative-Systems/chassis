<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Http\Middleware;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Northwestern\SysDev\Chassis\Http\Middleware\LogsApiRequests;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use Northwestern\SysDev\Chassis\ValueObjects\ApiRequestContext;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

// --- Concrete middleware for testing ---

class TestLogsApiRequests extends LogsApiRequests
{
    public static bool $enabled = true;

    public static bool $samplingEnabled = false;

    public static float $rate = 1.0;

    public static bool $throwOnPersist = false;

    /** @var list<array<string, mixed>> */
    public static array $logs = [];

    protected function isEnabled(): bool
    {
        return static::$enabled;
    }

    protected function isSamplingEnabled(): bool
    {
        return static::$samplingEnabled;
    }

    protected function sampleRate(): float
    {
        return static::$rate;
    }

    protected function persistLog(array $data): void
    {
        if (static::$throwOnPersist) {
            throw new RuntimeException('persist failure');
        }

        static::$logs[] = $data;
    }

    public static function reset(): void
    {
        static::$enabled = true;
        static::$samplingEnabled = false;
        static::$rate = 1.0;
        static::$throwOnPersist = false;
        static::$logs = [];
    }
}

#[CoversClass(LogsApiRequests::class)]
class LogsApiRequestsTest extends TestCase
{
    protected string $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        TestLogsApiRequests::reset();

        $this->endpoint = '/api/log-test';

        Route::middleware([TestLogsApiRequests::class])
            ->get($this->endpoint, fn () => response()->json(['ok' => true]));
    }

    protected function tearDown(): void
    {
        Context::flush();

        parent::tearDown();
    }

    public function test_skips_logging_when_disabled(): void
    {
        TestLogsApiRequests::$enabled = false;

        Context::add(ApiRequestContext::USER_ID, 1);

        $this->getJson($this->endpoint)->assertOk();

        $this->assertEmpty(TestLogsApiRequests::$logs);
    }

    public function test_logs_request_with_correct_fields_when_enabled(): void
    {
        Context::add(ApiRequestContext::TRACE_ID, 'test-trace-id');
        Context::add(ApiRequestContext::USER_ID, 42);
        Context::add(ApiRequestContext::TOKEN_ID, 7);

        $this->getJson($this->endpoint, ['User-Agent' => 'TestAgent/1.0'])
            ->assertOk();

        $this->assertCount(1, TestLogsApiRequests::$logs);

        $log = TestLogsApiRequests::$logs[0];

        $this->assertSame('test-trace-id', $log['trace_id']);
        $this->assertSame(42, $log['user_id']);
        $this->assertSame(7, $log['token_id']);
        $this->assertSame('GET', $log['method']);
        $this->assertSame('api/log-test', $log['path']);
        $this->assertSame(200, $log['status_code']);
        $this->assertIsInt($log['duration_ms']);
        $this->assertSame('TestAgent/1.0', $log['user_agent']);
        $this->assertNull($log['failure_reason']);
    }

    public function test_skips_logging_when_no_user_and_no_failure_reason(): void
    {
        $this->getJson($this->endpoint)->assertOk();

        $this->assertEmpty(TestLogsApiRequests::$logs);
    }

    public function test_always_logs_failures_regardless_of_sampling(): void
    {
        TestLogsApiRequests::$samplingEnabled = true;
        TestLogsApiRequests::$rate = 0.0;

        Context::add(ApiRequestContext::USER_ID, 1);
        Context::add(ApiRequestContext::FAILURE_REASON, 'token-invalid-or-expired');

        Route::middleware([TestLogsApiRequests::class])
            ->get('/api/fail-test', fn () => response()->json(['error' => 'fail'], 401));

        $this->getJson('/api/fail-test')->assertUnauthorized();

        $this->assertCount(1, TestLogsApiRequests::$logs);
        $this->assertSame('token-invalid-or-expired', TestLogsApiRequests::$logs[0]['failure_reason']);
    }

    public function test_respects_sampling_for_successful_requests(): void
    {
        TestLogsApiRequests::$samplingEnabled = true;
        TestLogsApiRequests::$rate = 0.0;

        Context::add(ApiRequestContext::USER_ID, 1);

        $this->getJson($this->endpoint)->assertOk();

        $this->assertEmpty(TestLogsApiRequests::$logs);
    }

    public function test_sets_x_trace_id_header_on_response(): void
    {
        Context::add(ApiRequestContext::TRACE_ID, 'my-trace-id');
        Context::add(ApiRequestContext::USER_ID, 1);

        $response = $this->getJson($this->endpoint);

        $response->assertOk();
        $response->assertHeader('X-Trace-Id', 'my-trace-id');
    }

    public function test_response_bytes_falls_back_to_content_length_from_body_when_header_absent(): void
    {
        Context::add(ApiRequestContext::USER_ID, 1);

        // Return a response with no Content-Length header so the middleware
        // falls back to computing byte size from the body contents.
        Route::middleware([TestLogsApiRequests::class])->get('/api/no-length', function () {
            $response = response('hello world', 200);
            $response->headers->remove('Content-Length');

            return $response;
        });

        $this->get('/api/no-length')->assertOk();

        $this->assertCount(1, TestLogsApiRequests::$logs);
        $this->assertSame(11, TestLogsApiRequests::$logs[0]['response_bytes']);
    }

    public function test_response_bytes_uses_content_length_header_when_present(): void
    {
        Context::add(ApiRequestContext::USER_ID, 1);

        Route::middleware([TestLogsApiRequests::class])->get('/api/with-length', function () {
            return response()->json(['data' => 'test'])->header('Content-Length', '1234');
        });

        $this->getJson('/api/with-length')->assertOk();

        $this->assertCount(1, TestLogsApiRequests::$logs);
        $this->assertSame(1234, TestLogsApiRequests::$logs[0]['response_bytes']);
    }

    public function test_streamed_response_does_not_capture_response_bytes(): void
    {
        Context::add(ApiRequestContext::USER_ID, 1);

        Route::middleware([TestLogsApiRequests::class])->get('/api/stream', function () {
            return response()->stream(function () {
                echo 'streaming data';
            });
        });

        $this->get('/api/stream')->assertOk();

        $this->assertCount(1, TestLogsApiRequests::$logs);
        $this->assertNull(TestLogsApiRequests::$logs[0]['response_bytes']);
    }

    public function test_exception_during_persist_is_swallowed_and_does_not_break_request(): void
    {
        TestLogsApiRequests::$throwOnPersist = true;

        Context::add(ApiRequestContext::USER_ID, 1);

        // Request still succeeds even though persistLog throws.
        $this->getJson($this->endpoint)->assertOk();

        // No log was stored because persistLog threw.
        $this->assertEmpty(TestLogsApiRequests::$logs);
    }

    public function test_sampling_with_fractional_rate_invokes_lottery_logic(): void
    {
        // Rate 0.5 falls through the 0.0-and-1.0 shortcuts and exercises the
        // Lottery code path (lines that handle fractional sampling).
        TestLogsApiRequests::$samplingEnabled = true;
        TestLogsApiRequests::$rate = 0.5;

        Context::add(ApiRequestContext::USER_ID, 1);

        \Illuminate\Support\Lottery::alwaysWin();

        try {
            $this->getJson($this->endpoint)->assertOk();
            $this->assertCount(1, TestLogsApiRequests::$logs);
        } finally {
            \Illuminate\Support\Lottery::determineResultsNormally();
        }

        TestLogsApiRequests::$logs = [];
        Context::add(ApiRequestContext::USER_ID, 1);

        \Illuminate\Support\Lottery::alwaysLose();

        try {
            $this->getJson($this->endpoint)->assertOk();
            $this->assertEmpty(TestLogsApiRequests::$logs);
        } finally {
            \Illuminate\Support\Lottery::determineResultsNormally();
        }
    }

    public function test_sampling_with_rate_at_or_above_one_logs_all_successful_requests(): void
    {
        TestLogsApiRequests::$samplingEnabled = true;
        TestLogsApiRequests::$rate = 1.0;

        Context::add(ApiRequestContext::USER_ID, 1);

        $this->getJson($this->endpoint)->assertOk();

        $this->assertCount(1, TestLogsApiRequests::$logs);
    }
}
