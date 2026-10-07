<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Http\Middleware;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Northwestern\SysDev\Chassis\Exceptions\UnknownOAuthClientException;
use Northwestern\SysDev\Chassis\Http\Middleware\DetectUnknownOAuthClient;
use Northwestern\SysDev\Chassis\Tests\PassportTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(DetectUnknownOAuthClient::class)]
#[CoversClass(UnknownOAuthClientException::class)]
final class DetectUnknownOAuthClientTest extends PassportTestCase
{
    private const string REDIRECT_URI = 'http://127.0.0.1:33418/callback';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('passport.middleware', [DetectUnknownOAuthClient::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Passport::authorizationView(fn () => response('Consent'));

        $handler = $this->app->make(ExceptionHandler::class);
        $this->assertInstanceOf(Handler::class, $handler);

        $handler->renderable(fn (UnknownOAuthClientException $e) => response('Reconnect your client: ' . $e->getMessage(), $e->getStatusCode()));
    }

    public function test_a_deleted_client_is_an_unknown_client(): void
    {
        $client = $this->publicClient();
        $client->delete();

        $this->authorize($client->getKey())
            ->assertBadRequest()
            ->assertSee('Reconnect your client: The application that sent you here is no longer registered.');
    }

    public function test_a_revoked_client_is_an_unknown_client(): void
    {
        $client = $this->publicClient();
        $client->forceFill(['revoked' => true])->save();

        $this->authorize($client->getKey())->assertBadRequest();
    }

    public function test_a_malformed_client_id_is_an_unknown_client(): void
    {
        $this->authorize('not-a-uuid')->assertBadRequest();
    }

    // Passport answers invalid_client for an unregistered redirect URI too; that client still exists.
    public function test_a_redirect_uri_the_client_did_not_register_is_unchanged(): void
    {
        $this->authorize($this->publicClient()->getKey(), 'https://evil.example/callback')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'invalid_client');
    }

    // Clients read the token endpoint's error, and some register again when they see it.
    public function test_the_token_endpoint_still_answers_clients_with_json(): void
    {
        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => '6b1f9e5c-0d4e-4a7b-9a8e-3c2d1e0f4a5b',
            'redirect_uri' => self::REDIRECT_URI,
            'code' => 'code',
            'code_verifier' => str_repeat('v', 64),
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error', 'invalid_client');
    }

    public function test_other_authorization_errors_are_unchanged(): void
    {
        $this->authorize($this->publicClient()->getKey(), responseType: 'unsupported')
            ->assertJsonPath('error', 'unsupported_grant_type');
    }

    private function publicClient(): Client
    {
        return $this->clients()->createAuthorizationCodeGrantClient('Claude Code', [self::REDIRECT_URI], confidential: false);
    }

    /**
     * @return TestResponse<Response>
     */
    private function authorize(string $clientId, string $redirectUri = self::REDIRECT_URI, string $responseType = 'code'): TestResponse
    {
        return $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => $responseType,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 64), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]));
    }
}
