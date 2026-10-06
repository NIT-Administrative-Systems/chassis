<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Northwestern\SysDev\Chassis\Testing\Browser\ClientErrors;
use Northwestern\SysDev\Chassis\Tests\Browser\Pages;
use PHPUnit\Framework\ExpectationFailedException;

const NOISY = '<script>console.error("Alpine Expression Error", { expression: "open" }); Promise.reject(new Error("Lost the promise"));</script>';

it('records console errors and unhandled rejections raised while the page loads', function () {
    $page = visit(Pages::serve('/noisy', NOISY));

    // Pest's own check doesn't see either.
    $page->assertNoJavaScriptErrors();

    expect(ClientErrors::of($page))->toBe([
        'console.error: Alpine Expression Error {"expression":"open"}',
        'Unhandled promise rejection: Lost the promise',
    ]);
});

it('records uncaught errors', function () {
    $page = visit(Pages::serve('/throws', '<script>throw new TypeError("Nope")</script>'));

    expect(ClientErrors::of($page))->toBe(['Uncaught error: Uncaught TypeError: Nope']);
});

it('reloads the page once, the first time it captures', function () {
    $loads = 0;
    Route::get('/counted', function () use (&$loads) {
        $loads++;

        return '<!doctype html><html lang="en"><head><title>Counted</title></head><body><main><h1>Counted</h1></main></body></html>';
    });

    $page = visit('/counted')->assertSee('Counted');
    ClientErrors::capture($page);
    ClientErrors::capture($page);

    expect($loads)->toBe(2);
});

it('records Livewire requests that fail', function () {
    Route::get('/search', fn () => Blade::render(<<<'BLADE'
        <!doctype html>
        <html lang="en"><head><title>Search</title>@livewireStyles</head>
        <body><main><h1>Search</h1><livewire:search /></main>@livewireScripts</body></html>
        BLADE))->middleware('web');

    $page = visit('/search')->assertSee('Searching for:');
    ClientErrors::capture($page);

    $page->click('@explode');
    $page->wait(0.5);

    expect(ClientErrors::of($page))->toContain('Livewire request failed with status 500');
});

it('passes a quiet page', function () {
    expect(visit(Pages::serve('/quiet', '<p>Quiet.</p>')))->toHaveNoClientErrors();
});

it('fails with each error', function () {
    $page = visit(Pages::serve('/noisy', NOISY));

    expect(fn () => expect($page)->toHaveNoClientErrors())->toThrow(
        ExpectationFailedException::class,
        "Expected [/noisy] to have no browser errors, found:\n- console.error: Alpine Expression Error",
    );
});
