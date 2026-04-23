<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Northwestern\SysDev\Chassis\Http\Middleware\EnvironmentLockdown;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

#[CoversClass(EnvironmentLockdown::class)]
class EnvironmentLockdownTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        resolve('router')->get('/lockdown', fn () => 'locked')->name('test.lockdown');
        resolve('router')->get('/dashboard', fn () => new Response('dashboard'))->name('dashboard');
        resolve('router')->get('/auth/login', fn () => new Response('login'))->name('auth.login');
    }

    private function createLockdown(bool $enabled = true, bool $authorized = false, array $exempted = []): EnvironmentLockdown
    {
        return new class($enabled, $authorized, $exempted) extends EnvironmentLockdown
        {
            public function __construct(
                private readonly bool $enabledFlag,
                private readonly bool $authorizedFlag,
                private readonly array $exempted,
            ) {
            }

            protected function isEnabled(): bool
            {
                return $this->enabledFlag;
            }

            protected function isAuthorized(Request $request): bool
            {
                return $this->authorizedFlag;
            }

            protected function redirectRoute(): string
            {
                return 'test.lockdown';
            }

            protected function exemptedRoutePatterns(): array
            {
                return $this->exempted;
            }
        };
    }

    public function test_passes_through_when_disabled(): void
    {
        $middleware = $this->createLockdown(enabled: false);

        $request = Request::create('/dashboard');
        $response = $middleware->handle($request, fn ($req) => new Response('ok'));

        $this->assertSame('ok', $response->getContent());
    }

    public function test_passes_through_for_guests(): void
    {
        $middleware = $this->createLockdown(enabled: true, authorized: false);

        $request = Request::create('/dashboard');
        $response = $middleware->handle($request, fn ($req) => new Response('ok'));

        $this->assertSame('ok', $response->getContent());
    }

    public function test_passes_through_when_authorized(): void
    {
        $middleware = $this->createLockdown(enabled: true, authorized: true);

        $request = Request::create('/dashboard');
        $request->setUserResolver(fn () => new stdClass());
        $response = $middleware->handle($request, fn ($req) => new Response('ok'));

        $this->assertSame('ok', $response->getContent());
    }

    public function test_redirects_when_not_authorized(): void
    {
        $middleware = $this->createLockdown(enabled: true, authorized: false);

        $request = Request::create('/dashboard');
        $request->setUserResolver(fn () => new stdClass());

        $response = $middleware->handle($request, fn ($req) => new Response('ok'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/lockdown', $response->headers->get('Location'));
    }

    public function test_passes_through_for_exempted_routes(): void
    {
        $middleware = $this->createLockdown(enabled: true, authorized: false, exempted: ['auth.*']);

        $request = Request::create('/auth/login');
        $request->setUserResolver(fn () => new stdClass());
        $request->setRouteResolver(fn () => resolve('router')->getRoutes()->match($request));

        $response = $middleware->handle($request, fn ($req) => new Response('ok'));

        $this->assertSame('ok', $response->getContent());
    }

    public function test_default_is_enabled_returns_false(): void
    {
        // Use the base class directly to exercise the default method bodies.
        $middleware = new EnvironmentLockdown();

        $request = Request::create('/dashboard');
        $response = $middleware->handle($request, fn ($req) => new Response('ok'));

        // Disabled by default, so the request passes through unchanged.
        $this->assertSame('ok', $response->getContent());
    }

    public function test_default_is_authorized_and_default_route_name(): void
    {
        // Use a subclass that only overrides isEnabled so we exercise
        // the default isAuthorized() (returns true) path.
        $middleware = new class extends EnvironmentLockdown
        {
            protected function isEnabled(): bool
            {
                return true;
            }
        };

        $request = Request::create('/dashboard');
        $request->setUserResolver(fn () => new stdClass());

        $response = $middleware->handle($request, fn ($req) => new Response('ok'));

        // Default isAuthorized() returns true, so the request passes through.
        $this->assertSame('ok', $response->getContent());
    }

    public function test_default_redirect_route_and_exempted_patterns(): void
    {
        // Register the default-named route so redirect() can resolve it.
        resolve('router')->get('/default-lockdown', fn () => 'locked')->name('lockdown');

        // Subclass that enables lockdown and denies the user, exercising the
        // default redirectRoute() (returns 'lockdown') and exemptedRoutePatterns()
        // (returns []) methods.
        $middleware = new class extends EnvironmentLockdown
        {
            protected function isEnabled(): bool
            {
                return true;
            }

            protected function isAuthorized(Request $request): bool
            {
                return false;
            }
        };

        $request = Request::create('/dashboard');
        $request->setUserResolver(fn () => new stdClass());

        $response = $middleware->handle($request, fn ($req) => new Response('ok'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/default-lockdown', (string) $response->headers->get('Location'));
    }
}
