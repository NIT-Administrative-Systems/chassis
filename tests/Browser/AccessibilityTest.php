<?php

declare(strict_types=1);

use Northwestern\SysDev\Chassis\Testing\Browser\Accessibility;
use Northwestern\SysDev\Chassis\Testing\Browser\AccessibilityImpact;
use Northwestern\SysDev\Chassis\Tests\Browser\Pages;
use PHPUnit\Framework\ExpectationFailedException;

afterEach(fn () => Accessibility::reset());

const MISSING_ALT = '<img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="photo">';
const LOW_CONTRAST = '<p class="faint" style="color: #ccc; background: #fff">Hard to read</p>';

it('reports violations of every impact by default', function () {
    $page = visit(Pages::serve('/broken', MISSING_ALT . LOW_CONTRAST));

    $rules = array_map(fn (Northwestern\SysDev\Chassis\Testing\Browser\AccessibilityViolation $violation) => [$violation->rule, $violation->impact], Accessibility::violations($page));

    expect($rules)->toEqualCanonicalizing([
        ['image-alt', AccessibilityImpact::Critical],
        ['color-contrast', AccessibilityImpact::Serious],
    ]);
});

it('leaves out excluded elements and disabled rules', function () {
    $page = visit(Pages::serve('/broken', MISSING_ALT . LOW_CONTRAST));

    expect(Accessibility::violations($page, exclude: ['.photo'], disableRules: ['color-contrast']))->toBe([]);
});

it('applies the configured defaults to every check', function () {
    Accessibility::configure(disableRules: ['image-alt'], exclude: ['.faint']);

    expect(Accessibility::violations(visit(Pages::serve('/broken', MISSING_ALT . LOW_CONTRAST))))->toBe([]);
});

it('ignores violations below the minimum impact', function () {
    Accessibility::configure(minimumImpact: AccessibilityImpact::Critical);

    $violations = Accessibility::violations(visit(Pages::serve('/broken', MISSING_ALT . LOW_CONTRAST)));

    expect(array_map(fn (Northwestern\SysDev\Chassis\Testing\Browser\AccessibilityViolation $violation) => $violation->rule, $violations))->toBe(['image-alt']);
});

it('checks colors as they settle, not partway through a transition or animation', function (string $head, string $script) {
    // Readable once settled; mid-way, the background is nearly as light as the text.
    $page = visit(Pages::serve('/settling', '<p class="note">Settling text</p>' . $script, $head));

    expect(Accessibility::violations($page))->toBe([]);
})->with([
    'a transition' => [
        '<style>.note { color: #fff; background: #f4f4f4; transition: background-color 60s; } .note.settled { background: #000; }</style>',
        "<script>requestAnimationFrame(() => requestAnimationFrame(() => document.querySelector('.note').classList.add('settled')));</script>",
    ],
    'an animation' => [
        '<style>.note { color: #fff; background: #000; animation: fade-in 60s; } @keyframes fade-in { from { background: #f4f4f4; } }</style>',
        '',
    ],
]);

it('passes an accessible page', function () {
    expect(visit(Pages::serve('/fine', '<p>Plain text.</p>')))->toBeAccessible();
});

it('fails with each rule, its help link and the failing elements', function () {
    $page = visit(Pages::serve('/broken', MISSING_ALT));

    expect(fn () => expect($page)->toBeAccessible())->toThrow(
        ExpectationFailedException::class,
        "Expected [/broken] to have no accessibility violations, found 1:\n- image-alt (critical): Images must have alternative text",
    );
});

it('takes exclusions and disabled rules at the call', function () {
    $page = visit(Pages::serve('/broken', MISSING_ALT . LOW_CONTRAST));

    expect($page)->toBeAccessible(exclude: ['.photo'], disableRules: ['color-contrast']);
});
