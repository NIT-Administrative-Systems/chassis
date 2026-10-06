<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Route;
use Northwestern\SysDev\Chassis\Testing\Browser\ServerErrors;
use Northwestern\SysDev\Chassis\Tests\Browser\Pages;
use PHPUnit\Framework\ExpectationFailedException;

it('records exceptions the application reports while serving the browser', function () {
    Route::get('/fails', fn () => throw new RuntimeException('The database is gone.'));

    visit('/fails')->assertSee('Server Error');

    expect(ServerErrors::reported())->toHaveCount(1)
        ->and(ServerErrors::reported()[0]->getMessage())->toBe('The database is gone.');
});

it("doesn't record exceptions Laravel doesn't report", function () {
    Route::get('/missing', fn () => abort(404));

    visit('/missing')->assertSee('Not Found');

    expect(ServerErrors::reported())->toBe([]);
});

it('needs exceptions to be faked', function () {
    app()->instance(ExceptionHandler::class, resolve(ExceptionHandler::class)->handler());

    ServerErrors::reported();
})->throws(LogicException::class, 'Call Exceptions::fake() before visiting');

it('passes when nothing was reported', function () {
    expect(visit(Pages::serve('/fine', '<p>Fine.</p>')))->toHaveNoServerErrors();
});

it('fails with each exception and where it was thrown', function () {
    Route::get('/fails', fn () => throw new RuntimeException('The database is gone.'));

    $page = visit('/fails');

    expect(fn () => expect($page)->toHaveNoServerErrors())->toThrow(
        ExpectationFailedException::class,
        "Expected the application to report no exceptions while serving [/fails], found:\n- RuntimeException: The database is gone. (",
    );
});
