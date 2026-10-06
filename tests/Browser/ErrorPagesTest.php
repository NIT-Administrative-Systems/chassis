<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Northwestern\SysDev\Chassis\Testing\Browser\ErrorPages;

it('serves an error page for a status', function () {
    expect(ErrorPages::path(503))->toBe('/__chassis/error-pages/503');

    expect(visit(ErrorPages::path(500))->assertSee('Server Error'))->toHaveNoServerErrors();
});

it('registers its route once', function () {
    ErrorPages::path(500);
    ErrorPages::path(404);

    expect(collect(Route::getRoutes()->getRoutes())->filter(
        fn ($route) => $route->getName() === ErrorPages::ROUTE_NAME,
    ))->toHaveCount(1);
});

it('only serves error statuses', function () {
    ErrorPages::path(302);
})->throws(InvalidArgumentException::class, 'An error page needs a 4xx or 5xx status, not 302.');
