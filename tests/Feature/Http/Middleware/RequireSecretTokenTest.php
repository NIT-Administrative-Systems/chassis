<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Northwestern\SysDev\Chassis\Http\Middleware\RequireSecretToken;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RequireSecretToken::class)]
class RequireSecretTokenTest extends TestCase
{
    private string $endpoint = '/api/secret-token-test';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware([RequireSecretToken::class . ':health.secret_token'])
            ->get($this->endpoint, fn () => response()->json(['ok' => true]));
    }

    // Spatie Laravel Health's own middleware treats an empty token as "no check".
    public function test_the_endpoint_is_closed_until_a_token_is_set(): void
    {
        foreach ([null, '', ['not', 'a', 'string']] as $token) {
            config(['health.secret_token' => $token]);

            $this->getJson($this->endpoint)->assertForbidden();
            $this->getJson($this->endpoint, ['X-Secret-Token' => ''])->assertForbidden();
        }
    }

    public function test_a_wrong_or_missing_token_is_refused(): void
    {
        config(['health.secret_token' => 'expected-token']);

        $this->getJson($this->endpoint)->assertForbidden();
        $this->getJson($this->endpoint, ['X-Secret-Token' => 'wrong-token'])->assertForbidden();
    }

    public function test_the_right_token_reaches_the_route(): void
    {
        config(['health.secret_token' => 'expected-token']);

        $this->getJson($this->endpoint, ['X-Secret-Token' => 'expected-token'])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_a_missing_config_key_parameter_is_a_misconfiguration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs the config key holding the token');

        (new RequireSecretToken())->handle(Request::create($this->endpoint), fn () => response()->json(['ok' => true]));
    }
}
