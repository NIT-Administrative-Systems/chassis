<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Http\Controllers;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Northwestern\SysDev\Chassis\Http\Controllers\SentryTunnelController;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SentryTunnelController::class)]
class SentryTunnelControllerTest extends TestCase
{
    private string $endpoint = '/sentry/tunnel';

    private string $dsn = 'https://public-key@o123.ingest.us.sentry.io/4567';

    protected function setUp(): void
    {
        parent::setUp();

        Route::post($this->endpoint, SentryTunnelController::class);

        config(['sentry.dsn' => $this->dsn]);

        Http::preventStrayRequests();
    }

    public function test_matching_envelope_is_forwarded_to_the_dsn_ingest_host(): void
    {
        Http::fake([
            'https://o123.ingest.us.sentry.io/api/4567/envelope/' => Http::response('{"id":"abc"}', 200, ['Content-Type' => 'application/json']),
        ]);

        $envelope = $this->envelope($this->dsn);

        $this->postEnvelope($envelope)
            ->assertOk()
            ->assertContent('{"id":"abc"}')
            ->assertHeader('Content-Type', 'application/json');

        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://o123.ingest.us.sentry.io/api/4567/envelope/'
            && $request->body() === $envelope
            && $request->hasHeader('Content-Type', 'application/x-sentry-envelope'));
    }

    public function test_upstream_status_is_passed_through(): void
    {
        Http::fake([
            'https://o123.ingest.us.sentry.io/*' => Http::response('rate limited', 429),
        ]);

        $this->postEnvelope($this->envelope($this->dsn))->assertTooManyRequests();
    }

    public function test_envelope_for_a_different_project_is_rejected(): void
    {
        Http::fake();

        $this->postEnvelope($this->envelope('https://public-key@o123.ingest.us.sentry.io/9999'))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_envelope_for_a_different_host_is_rejected(): void
    {
        Http::fake();

        $this->postEnvelope($this->envelope('https://public-key@attacker.example.com/4567'))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_envelope_with_a_different_public_key_is_rejected(): void
    {
        Http::fake();

        $this->postEnvelope($this->envelope('https://other-key@o123.ingest.us.sentry.io/4567'))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_envelopes_are_rejected_when_no_dsn_is_configured(): void
    {
        Http::fake();
        config(['sentry.dsn' => null]);

        $this->postEnvelope($this->envelope($this->dsn))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_envelope_without_a_dsn_header_is_unprocessable(): void
    {
        Http::fake();

        $this->postEnvelope('{"event_id":"abc"}' . "\n" . '{"type":"event"}')->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_malformed_envelope_is_unprocessable(): void
    {
        Http::fake();

        $this->postEnvelope('not json')->assertUnprocessable();
        $this->postEnvelope('')->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_dsn_path_prefix_is_preserved_for_self_hosted_sentry(): void
    {
        $dsn = 'https://public-key@sentry.example.edu/prefix/42';
        config(['sentry.dsn' => $dsn]);

        Http::fake([
            'https://sentry.example.edu/prefix/api/42/envelope/' => Http::response('{}'),
        ]);

        $this->postEnvelope($this->envelope($dsn))->assertOk();

        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://sentry.example.edu/prefix/api/42/envelope/');
    }

    public function test_unreachable_upstream_returns_bad_gateway(): void
    {
        Http::fake([
            'https://o123.ingest.us.sentry.io/*' => Http::failedConnection(),
        ]);

        $this->postEnvelope($this->envelope($this->dsn))->assertStatus(502);
    }

    private function envelope(string $dsn): string
    {
        return json_encode(['event_id' => 'abc', 'dsn' => $dsn], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            . "\n" . '{"type":"event"}' . "\n" . '{"message":"hello"}';
    }

    private function postEnvelope(string $envelope): TestResponse
    {
        return $this->call(
            'POST',
            $this->endpoint,
            server: ['CONTENT_TYPE' => 'application/x-sentry-envelope'],
            content: $envelope,
        );
    }
}
