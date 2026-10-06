<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Testing\Browser;

use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

/**
 * Test routes that answer with an error status, so a browser test can visit the application's
 * error pages, including the ones only a server failure shows (500, 503).
 *
 * Pest's browser plugin serves the routes a test registers, so the route exists only in the
 * test that asks for it.
 */
final class ErrorPages
{
    public const string ROUTE_NAME = 'chassis.testing.error-page';

    /**
     * Register the route, under the `web` middleware group, if it isn't yet and return the
     * path that answers with `$status`.
     */
    public static function path(int $status): string
    {
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException("An error page needs a 4xx or 5xx status, not {$status}.");
        }

        if (! Route::has(self::ROUTE_NAME)) {
            Route::middleware('web')
                ->get('/__chassis/error-pages/{status}', static fn (int $status) => abort($status))
                ->whereNumber('status')
                ->name(self::ROUTE_NAME);

            Route::getRoutes()->refreshNameLookups();
        }

        return '/__chassis/error-pages/' . $status;
    }
}
