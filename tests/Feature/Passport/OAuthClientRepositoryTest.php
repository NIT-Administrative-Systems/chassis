<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Passport;

use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Northwestern\SysDev\Chassis\Passport\OAuthClientRepository;
use Northwestern\SysDev\Chassis\Tests\PassportTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(OAuthClientRepository::class)]
final class OAuthClientRepositoryTest extends PassportTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(ClientRepository::class, OAuthClientRepository::class);
    }

    protected function tearDown(): void
    {
        Passport::$clientUuids = true;

        parent::tearDown();
    }

    public function test_a_malformed_client_id_is_an_unknown_client_found_without_a_query(): void
    {
        DB::enableQueryLog();

        $this->assertNull($this->clients()->find('not-a-uuid'));
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_the_token_endpoint_answers_invalid_client_for_a_malformed_client_id(): void
    {
        $this->postJson('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => 'not-a-uuid', 'client_secret' => 'secret'])
            ->assertUnauthorized()
            ->assertJsonPath('error', 'invalid_client');
    }

    public function test_a_real_client_is_still_found(): void
    {
        $client = $this->clients()->createClientCredentialsGrantClient('Nightly sync');

        $this->assertTrue($this->clients()->find($client->getKey())?->is($client));
    }

    public function test_any_id_is_looked_up_when_clients_do_not_use_uuids(): void
    {
        Passport::$clientUuids = false;
        DB::enableQueryLog();

        $this->assertNull($this->clients()->find(42));
        $this->assertCount(1, DB::getQueryLog());
    }
}
