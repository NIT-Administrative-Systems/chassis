<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Northwestern\SysDev\Chassis\Testing\Browser\ClientErrors;
use Northwestern\SysDev\Chassis\Testing\Browser\LivewireRequests;

beforeEach(function () {
    Route::get('/search', fn () => Blade::render(<<<'BLADE'
        <!doctype html>
        <html lang="en"><head><title>Search</title>@livewireStyles</head>
        <body><main><h1>Search</h1><livewire:search /></main>@livewireScripts</body></html>
        BLADE))->middleware('web');
});

it('waits out a debounced field and its request', function () {
    $page = visit('/search')->assertSee('Searching for:');
    ClientErrors::capture($page);

    $page->type('@query', 'enrollment');
    LivewireRequests::settle($page, debounce: 400);

    // Read once, without retrying, so the test fails if settling returned early.
    expect($page->text('@echo'))->toBe('Searching for: enrollment');
});

it('waits for a request in flight', function () {
    $page = visit('/search')->assertSee('Searching for:');
    ClientErrors::capture($page);

    $page->click('@slow');
    LivewireRequests::settle($page);

    expect($page->text('@echo'))->toBe('Searching for: slow answer');
});

it('needs the page to be capturing', function () {
    LivewireRequests::settle(visit('/search')->assertSee('Searching for:'));
})->throws(LogicException::class, 'Call ClientErrors::capture($page) before interacting with it.');
