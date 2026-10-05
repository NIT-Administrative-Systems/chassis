<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests;

use DateTimeImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\Bridge\AccessToken as AccessTokenEntity;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Bridge\Client as ClientEntity;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\PassportServiceProvider;
use League\OAuth2\Server\CryptKey;
use Northwestern\SysDev\Chassis\Tests\Fixtures\Passport\PassportUser;

/**
 * A Testbench application with Laravel Passport installed: its migrations, an `api` guard on
 * the passport driver, signing keys, and helpers to issue tokens for any grant.
 */
abstract class PassportTestCase extends TestCase
{
    /** @var array{private: string, public: string}|null */
    private static ?array $keys = null;

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            PassportServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $keys = $this->keys();

        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('auth.guards.api', ['driver' => 'passport', 'provider' => 'users']);
        $app['config']->set('auth.providers.users', ['driver' => 'eloquent', 'model' => PassportUser::class]);
        $app['config']->set('passport.private_key', $keys['private']);
        $app['config']->set('passport.public_key', $keys['public']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../vendor/laravel/passport/database/migrations');

        Passport::tokensCan([
            'users:view' => 'View users',
            'users:edit' => 'Edit users',
        ]);
    }

    protected function createUser(bool $active = true): PassportUser
    {
        return PassportUser::query()->create(['name' => 'Willie Wildcat', 'active' => $active]);
    }

    protected function clients(): ClientRepository
    {
        return resolve(ClientRepository::class);
    }

    /**
     * Issue an access token directly, as Passport's grants do, so tests can cover every grant
     * without running its HTTP flow. Returns the bearer token.
     *
     * @param  list<string>  $scopes
     */
    protected function issueToken(Client $client, ?PassportUser $user, array $scopes = [], string $expiresIn = '+1 hour'): string
    {
        $entity = new AccessTokenEntity(
            $user instanceof PassportUser ? (string) $user->getKey() : null,
            array_map(fn (string $scope): Scope => new Scope($scope), $scopes),
            new ClientEntity((string) $client->getKey(), $client->name, [], $client->confidential(), $client->provider, $client->grant_types),
        );

        $entity->setIdentifier(Str::random(80));
        $entity->setExpiryDateTime(new DateTimeImmutable($expiresIn));
        $entity->setPrivateKey(new CryptKey($this->keys()['private'], null, false));

        resolve(AccessTokenRepository::class)->persistNewAccessToken($entity);

        return $entity->toString();
    }

    /**
     * @return array{private: string, public: string}
     */
    private function keys(): array
    {
        if (self::$keys === null) {
            $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($resource, $private);
            $details = openssl_pkey_get_details($resource);

            self::$keys = ['private' => $private, 'public' => $details['key']];
        }

        return self::$keys;
    }
}
