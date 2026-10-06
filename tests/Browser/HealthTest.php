<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Northwestern\SysDev\Chassis\Testing\Browser\Expectations;
use Northwestern\SysDev\Chassis\Testing\Browser\FilamentPages;
use Northwestern\SysDev\Chassis\Tests\Browser\Pages;
use PHPUnit\Framework\ExpectationFailedException;

const DARK_ONLY_CONTRAST = '<style>@media (prefers-color-scheme: dark) { .note { color: #333; background: #000; } }</style>';
const NARROW_ONLY_CONTRAST = '<style>@media (max-width: 600px) { .note { color: #ccc; background: #fff; } }</style>';

it('passes a healthy Filament page', function () {
    $this->signIn();

    expect(visit('/admin/widgets'))->toBeHealthy();
});

it('lists every kind of problem at once', function () {
    Route::get('/fails', fn () => throw new RuntimeException('The database is gone.'));
    $page = visit(Pages::serve('/sick', '<img src="/missing.png" alt="Missing"><img src="/missing-too.png"><script>console.error("Broken"); fetch("/fails");</script>'));

    expect(fn () => expect($page)->toBeHealthy())->toThrow(function (ExpectationFailedException $failure) {
        expect($failure->getMessage())
            ->toContain('Expected [/sick] to be healthy.')
            ->toContain("Accessibility violations:\n- image-alt (critical)")
            ->toContain("Broken images:\n- http://")
            ->toContain("Browser errors:\n- console.error: Broken")
            ->toContain("Server errors:\n- RuntimeException: The database is gone.");
    });
});

it('checks the page in light and dark mode', function () {
    $page = visit(Pages::serve('/themed', '<p class="note">Readable in light mode only.</p>', DARK_ONLY_CONTRAST));

    expect($page)->toBeHealthy();
    expect(fn () => expect($page)->toBeHealthyInEachTheme())->toThrow(ExpectationFailedException::class, 'color-contrast');
});

it('passes a page that works in both themes', function () {
    $this->signIn();

    expect(visit('/admin/widgets'))->toBeHealthyInEachTheme();
});

it('checks the page at a phone width', function () {
    $page = visit(Pages::serve('/narrow', '<p class="note">Readable on a desktop only.</p>', NARROW_ONLY_CONTRAST));

    expect($page)->toBeHealthy();
    expect(fn () => expect($page)->toBeHealthyOnMobile())->toThrow(ExpectationFailedException::class, 'color-contrast');
    expect(visit(Pages::serve('/wide', '<p>Readable anywhere.</p>')))->toBeHealthyOnMobile();
});

it('checks a list of pages and reports every unhealthy one', function () {
    Pages::serve('/fine', '<p>Fine.</p>');
    Pages::serve('/broken', '<img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7">');

    expect(fn () => expect(['/fine', '/broken'])->toAllBeHealthy())->toThrow(
        ExpectationFailedException::class,
        "1 of 2 pages aren't healthy.\n\nExpected [/broken] to be healthy.",
    );
});

it('checks every page of a Filament panel', function () {
    $this->signIn();

    expect(FilamentPages::in('admin'))->toAllBeHealthy();
});

it('needs a page from visit()', function () {
    Expectations::page('/admin');
})->throws(UnexpectedValueException::class, 'Expected a page from visit(), got [string].');
