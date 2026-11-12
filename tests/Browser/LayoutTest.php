<?php

declare(strict_types=1);

use Northwestern\SysDev\Chassis\Testing\Browser\FilamentPages;
use Northwestern\SysDev\Chassis\Testing\Browser\Layout;
use Northwestern\SysDev\Chassis\Tests\Browser\Pages;
use PHPUnit\Framework\ExpectationFailedException;

const WIDE_TABLE = '<table style="width: 500px"><tr><th>Name</th><th>Email</th></tr><tr><td>Pat</td><td>pat@example.com</td></tr></table>';

it('passes a page that fits every width', function () {
    $page = visit(Pages::serve('/fits', <<<'HTML'
        <header><a href="/">Home</a> <button type="button">Menu</button></header>
        <div style="border: 1px solid; padding: 16px">
            <table style="width: 100%"><tr><th>Name</th></tr><tr><td>Pat</td></tr></table>
        </div>
        HTML));

    expect($page)->toFitEachWidth();
});

it('names what pushes the page wider than the screen', function () {
    $page = Layout::visit(Pages::serve('/wide', '<div class="banner" style="width: 600px">A wide banner</div>'), 320);

    expect(fn () => expect($page)->toFitTheScreen())->toThrow(function (ExpectationFailedException $failure) {
        expect($failure->getMessage())
            ->toContain("Expected [/wide] to fit a 320px screen.\n- The page is ")
            ->toContain('wide on a 320px screen, so it scrolls sideways, pushed out by div.banner "A wide banner" (its right edge is at 608px).');
    });
});

it('fails when controls in the header overlap', function () {
    $page = visit(Pages::serve('/crowded', <<<'HTML'
        <header style="position: relative; height: 48px">
            <a href="/" style="position: absolute; left: 0; width: 100px">Home</a>
            <button type="button" style="position: absolute; left: 60px; width: 100px">Menu</button>
        </header>
        HTML));

    expect(fn () => expect($page)->toFitTheScreen())->toThrow(
        ExpectationFailedException::class,
        'In the header, a "Home" overlaps button "Menu" by 40px × ',
    );
});

it('fails when a table runs past the box it is in', function () {
    // The page is wide enough; only the card is too narrow for the table.
    $page = visit(Pages::serve('/card', '<div class="card" style="width: 300px; border: 1px solid">' . WIDE_TABLE . '</div>'));

    expect(fn () => expect($page)->toFitTheScreen())->toThrow(
        ExpectationFailedException::class,
        'The table table "Name Email" is 500px wide and runs 200px past its 300px container div.card.',
    );
});

it('fails when a table runs past its card where the page clips the overflow', function () {
    $cell = '<td style="white-space: nowrap">livewire:configure-s3-upload-cleanup</td>';
    $path = Pages::serve('/schedule', <<<HTML
        <div style="overflow-x: clip">
            <section class="card" style="border: 1px solid; padding: 16px">
                <table><tr><th>Command</th><th>Schedule</th><th>Next Run</th></tr><tr>{$cell}{$cell}{$cell}</tr></table>
            </section>
        </div>
        HTML);

    expect(fn () => expect(Layout::visit($path, 768))->toFitTheScreen())->toThrow(
        ExpectationFailedException::class,
        'px container section.card.',
    );
    expect(Layout::visit($path, 1440))->toFitTheScreen();
});

it('fails when a table is cut off by the box it is in', function () {
    $page = visit(Pages::serve('/clipped', '<div class="card" style="width: 300px; overflow: hidden">' . WIDE_TABLE . '</div>'));

    expect(fn () => expect($page)->toFitTheScreen())->toThrow(
        ExpectationFailedException::class,
        'wide and cut off by its 300px container div.card.',
    );
});

it('lets a full-bleed table reach into its box padding', function () {
    $page = visit(Pages::serve('/full-bleed', <<<'HTML'
        <div style="width: 300px; padding: 16px; border: 1px solid">
            <table style="width: calc(100% + 32px); margin-inline: -16px"><tr><td>Pat</td></tr></table>
        </div>
        HTML));

    expect($page)->toFitTheScreen();
});

it('fails when content makes a scroll container scroll sideways', function (string $content, string $message) {
    $page = visit(Pages::serve('/scrolls', '<div class="wrap" style="width: 300px; overflow-x: auto">' . $content . '</div>'));

    expect(fn () => expect($page)->toFitTheScreen())->toThrow(ExpectationFailedException::class, $message);
})->with([
    'a table' => [WIDE_TABLE, 'The table table "Name Email" is 500px wide in the 300px scroll container div.wrap, so the container scrolls sideways.'],
    'any other element' => ['<dl style="width: 500px"><dt>Email</dt><dd>pat@example.com</dd></dl>', 'The element dl "Email" is 500px wide in the 300px scroll container div.wrap, so the container scrolls sideways.'],
]);

it('passes a container that scrolls by design', function (string $container) {
    $wide = '<span style="flex: none; display: inline-block; width: 200px">Item</span>';

    expect(visit(Pages::serve('/designed', sprintf($container, str_repeat($wide, 3)))))->toFitTheScreen();
})->with([
    'a code block' => ['<pre style="width: 300px; overflow-x: auto">%s</pre>'],
    'a focusable scroll region' => ['<div role="region" aria-label="Schedule" tabindex="0" style="width: 300px; overflow-x: auto; white-space: nowrap">%s</div>'],
    'a carousel' => ['<div style="width: 300px; overflow-x: auto; scroll-snap-type: x mandatory; white-space: nowrap">%s</div>'],
    'a row of tabs' => ['<nav aria-label="Tabs" style="width: 300px; overflow-x: auto; display: flex">%s</nav>'],
]);

it('leaves out the elements it is told to', function () {
    $page = visit(Pages::serve('/excluded', '<div class="wrap" style="width: 300px; overflow-x: auto">' . WIDE_TABLE . '</div>'));

    expect($page)->toFitTheScreen(exclude: ['.wrap']);
});

it('fails when a badge runs past its cell', function () {
    $page = visit(Pages::serve('/badge', <<<'HTML'
        <table style="table-layout: fixed; width: 300px">
            <tr><td style="width: 100px"><span class="fi-badge" style="display: inline-block; white-space: nowrap">Graduate Program Coordinators</span></td><td>Pat</td></tr>
        </table>
        HTML));

    expect(fn () => expect($page)->toFitTheScreen())->toThrow(
        ExpectationFailedException::class,
        'The badge span.fi-badge "Graduate Program Coordinators" runs ',
    );
});

it('checks the height of controls on a touch screen when asked', function () {
    $path = Pages::serve('/small-button', '<button type="button" style="height: 30px">Save</button>');

    expect(fn () => expect(Layout::visit($path, 320))->toFitTheScreen(tapTargets: true))->toThrow(
        ExpectationFailedException::class,
        'The control button "Save" is 30px tall, under the 44px a finger needs.',
    );
    expect(Layout::visit($path, 320))->toFitTheScreen();
    expect(Layout::visit($path, 1440))->toFitTheScreen(tapTargets: true);
});

it('lists every width the page does not fit', function () {
    $page = visit(Pages::serve('/medium', '<div style="width: 900px">Wide</div>'));

    expect(fn () => expect($page)->toFitEachWidth())->toThrow(function (ExpectationFailedException $failure) {
        expect($failure->getMessage())
            ->toContain('Expected [/medium] to fit a 320px screen.')
            ->toContain('Expected [/medium] to fit a 768px screen.')
            ->not->toContain('1024px screen');
    });
});

it('checks a list of pages and reports every one that does not fit', function () {
    Pages::serve('/fine', '<p>Fine.</p>');
    Pages::serve('/wide', '<div style="width: 600px">Wide</div>');

    expect(fn () => expect(['/fine', '/wide'])->toAllFitEachWidth(widths: [320, 1024]))->toThrow(
        ExpectationFailedException::class,
        "1 of 2 pages don't fit every width.\n\nExpected [/wide] to fit a 320px screen.",
    );
});

// Each width keeps one browser page, which navigates from path to path.
it('measures each page in the list at its own widths', function () {
    Pages::serve('/fine', '<p>Fine.</p>');
    Pages::serve('/wide', '<div style="width: 600px">Wide</div>');

    expect(fn () => expect(['/wide', '/fine'])->toAllFitEachWidth(widths: [320, 1024]))->toThrow(function (ExpectationFailedException $failure) {
        expect($failure->getMessage())
            ->toStartWith("1 of 2 pages don't fit every width.\n\nExpected [/wide] to fit a 320px screen.")
            ->not->toContain('[/fine]')
            ->not->toContain('[/wide] to fit a 1024px screen');
    });
});

it('fits every page of a Filament panel at every width', function () {
    $this->signIn();

    expect(FilamentPages::in('admin'))->toAllFitEachWidth();
});
