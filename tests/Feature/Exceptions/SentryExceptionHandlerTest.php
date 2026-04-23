<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Exceptions;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Northwestern\SysDev\Chassis\Exceptions\SentryExceptionHandler;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;
use RuntimeException;
use Throwable;

#[CoversClass(SentryExceptionHandler::class)]
class SentryExceptionHandlerTest extends TestCase
{
    private function createSentryHandler(): SentryExceptionHandler
    {
        return new class extends SentryExceptionHandler
        {
            protected function userContext(Authenticatable $user): array
            {
                return [
                    'id' => $user->getAuthIdentifier(),
                ];
            }
        };
    }

    public function test_skips_when_sentry_is_not_bound(): void
    {
        $handler = $this->createSentryHandler();

        // Should not throw — just silently returns
        $handler->report(new RuntimeException('test'));

        $this->assertTrue(true);
    }

    public function test_reports_exception_when_sentry_is_bound(): void
    {
        $sentrySdk = new class
        {
            public bool $captured = false;

            public ?Throwable $exception = null;

            public function captureException(Throwable $e): void
            {
                $this->captured = true;
                $this->exception = $e;
            }
        };

        app()->instance('sentry', $sentrySdk);

        $handler = $this->createSentryHandler();
        $exception = new RuntimeException('something broke');
        $handler->report($exception);

        $this->assertTrue($sentrySdk->captured);
        $this->assertSame($exception, $sentrySdk->exception);
    }

    public function test_works_without_authenticated_user(): void
    {
        $sentrySdk = new class
        {
            public bool $captured = false;

            public function captureException(Throwable $e): void
            {
                $this->captured = true;
            }
        };

        app()->instance('sentry', $sentrySdk);

        $handler = $this->createSentryHandler();
        $handler->report(new RuntimeException('test'));

        // Captures the exception even though no user context is attached
        $this->assertTrue($sentrySdk->captured);
    }

    public function test_reports_when_guards_are_resolved_but_no_user_is_authenticated(): void
    {
        $sentrySdk = new class
        {
            public bool $captured = false;

            public function captureException(Throwable $e): void
            {
                $this->captured = true;
            }
        };

        app()->instance('sentry', $sentrySdk);

        // Force guards to be resolved but without a logged-in user.
        // Calling auth()->check() resolves the default guard.
        $this->assertFalse(auth()->check());

        $handler = $this->createSentryHandler();
        $handler->report(new RuntimeException('test'));

        $this->assertTrue($sentrySdk->captured);
    }

    public function test_reports_when_user_is_authenticated_but_sentry_sdk_functions_are_missing(): void
    {
        $sentrySdk = new class
        {
            public bool $captured = false;

            public function captureException(Throwable $e): void
            {
                $this->captured = true;
            }
        };

        app()->instance('sentry', $sentrySdk);

        $user = new GenericUser(['id' => 123, 'email' => 'test@example.com']);
        Auth::setUser($user);

        $handler = $this->createSentryHandler();
        $handler->report(new RuntimeException('test'));

        $this->assertTrue($sentrySdk->captured);

        // The Sentry SDK is suggested but not installed in the chassis test env,
        // so `function_exists('Sentry\\configureScope')` returns false and the
        // user-context attachment is skipped gracefully.
    }

    public function test_skips_user_context_when_auth_user_is_not_authenticatable(): void
    {
        $sentrySdk = new class
        {
            public bool $captured = false;

            public function captureException(Throwable $e): void
            {
                $this->captured = true;
            }
        };

        app()->instance('sentry', $sentrySdk);

        // Install a custom guard whose check() returns true but user() returns null.
        Auth::extend('stub-null-user', fn () => new class implements \Illuminate\Contracts\Auth\Guard
        {
            public function check(): bool
            {
                return true;
            }

            public function guest(): bool
            {
                return false;
            }

            public function user(): ?Authenticatable
            {
                // Intentionally returns null so the `! $user instanceof Authenticatable` guard triggers.
                return null;
            }

            public function id(): ?int
            {
                return null;
            }

            /** @param array<string, mixed> $credentials */
            public function validate(array $credentials = []): bool
            {
                return false;
            }

            public function hasUser(): bool
            {
                return true;
            }

            public function setUser(Authenticatable $user): void
            {
            }
        });
        config()->set('auth.guards.stub', ['driver' => 'stub-null-user']);
        config()->set('auth.defaults.guard', 'stub');

        // Resolve the guard so hasResolvedGuards() returns true.
        $this->assertTrue(auth()->check());

        $handler = $this->createSentryHandler();
        $handler->report(new RuntimeException('test'));

        $this->assertTrue($sentrySdk->captured);
    }

    public function test_user_context_returns_auth_identifier_by_default(): void
    {
        $handler = new SentryExceptionHandler();
        $user = new GenericUser(['id' => 456, 'email' => 'test@example.com']);

        // Call the protected userContext() via reflection to verify default behavior.
        $reflection = new ReflectionClass(SentryExceptionHandler::class);
        $method = $reflection->getMethod('userContext');
        $method->setAccessible(true);

        $context = $method->invoke($handler, $user);

        $this->assertSame(['id' => 456], $context);
    }
}
