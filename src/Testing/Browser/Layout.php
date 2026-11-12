<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Testing\Browser;

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;

/**
 * Checks a page's layout at one screen width: the page mustn't scroll sideways,
 * no two controls in a header may overlap, tables and badges must stay inside
 * their boxes, and a touch screen's controls can be held to 44 pixels tall.
 *
 * A scroll container whose content is wider than it fails, unless it scrolls by
 * design: a `pre` or `textarea`, a box with `scroll-snap-type`, a region that
 * takes focus so the keyboard can scroll it (`tabindex="0"`), or a box that
 * lays out its items in a single row, like a tab bar. Pass `exclude` the
 * selectors of any other container that is meant to scroll sideways.
 */
final class Layout
{
    /**
     * A small phone, a tablet, a small laptop and a desktop.
     *
     * @var list<int>
     */
    public const array WIDTHS = [320, 768, 1024, 1440];

    private const string SCRIPT = <<<'JS'
        async ([tapTargets, selectors]) => {
            await document.fonts.ready;

            // Playwright passes an empty PHP array as an object.
            const exclude = Object.values(selectors ?? {});

            const root = document.documentElement;
            const viewport = root.clientWidth;
            const problems = [];
            const style = (element) => getComputedStyle(element);
            const shown = (element) => element.checkVisibility() && element.getBoundingClientRect().width > 0;
            const excluded = (element) => exclude.some((selector) => element.closest(selector) !== null);
            const all = [...document.body.querySelectorAll('*')].filter(shown);
            const px = (value) => `${Math.round(value)}px`;

            const label = (element) => {
                const caption = element.tagName === 'TABLE'
                    ? element.caption?.innerText ?? element.closest('.fi-ta-ctn')?.querySelector('.fi-ta-header-heading')?.innerText
                    : null;
                const text = caption || element.getAttribute('aria-label') || element.innerText || element.value || element.getAttribute('title') || '';

                return text.trim().split('\n')[0].replace(/\s+/g, ' ').trim().slice(0, 40);
            };

            const selector = (element) => {
                const tag = element.tagName.toLowerCase();
                const classes = typeof element.className === 'string' ? element.className.trim().split(/\s+/) : [];

                return element.id ? `${tag}#${element.id}` : (classes[0] ? `${tag}.${classes[0]}` : tag);
            };

            const name = (element) => {
                const text = label(element);

                return text ? `${selector(element)} "${text}"` : selector(element);
            };

            const scrollsByDesign = (element) => {
                const { display, flexDirection, flexWrap, gridAutoFlow, scrollSnapType } = style(element);
                const oneRow = (display.endsWith('flex') && flexDirection.startsWith('row') && flexWrap === 'nowrap')
                    || (display.endsWith('grid') && gridAutoFlow.startsWith('column'));

                return element.matches('pre, textarea')
                    || scrollSnapType !== 'none'
                    || (element.hasAttribute('tabindex') && element.tabIndex >= 0)
                    || oneRow;
            };

            if (root.scrollWidth > viewport + 1) {
                // Content inside a container that clips or scrolls can't widen the page.
                const contained = (element) => {
                    for (let parent = element.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
                        if (style(parent).overflowX !== 'visible') {
                            return true;
                        }
                    }

                    return false;
                };

                const past = new Set(all.filter((element) => element.getBoundingClientRect().right > viewport + 1
                    && style(element).position !== 'fixed'
                    && ! contained(element)));
                const outermost = [...past].filter((element) => ! past.has(element.parentElement)).slice(0, 3)
                    .map((element) => `${name(element)} (its right edge is at ${px(element.getBoundingClientRect().right)})`);

                problems.push(`The page is ${root.scrollWidth}px wide on a ${viewport}px screen, so it scrolls sideways`
                    + (outermost.length > 0 ? `, pushed out by ${outermost.join(', ')}.` : '.'));
            }

            for (const bar of all.filter((element) => element.matches('header, [role="banner"], .fi-topbar') && ! excluded(element))) {
                const controls = [...bar.querySelectorAll('a, button, input, select, textarea, summary, [role="button"]')]
                    .filter(shown)
                    .filter((control) => ! control.closest('.sr-only'));

                controls.forEach((control, index) => controls.slice(index + 1).forEach((other) => {
                    const [a, b] = [control.getBoundingClientRect(), other.getBoundingClientRect()];
                    const width = Math.min(a.right, b.right) - Math.max(a.left, b.left);
                    const height = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);

                    if (! control.contains(other) && ! other.contains(control) && width > 1 && height > 1) {
                        problems.push(`In the header, ${name(control)} overlaps ${name(other)} by ${px(width)} × ${px(height)}.`);
                    }
                }));
            }

            for (const container of all.filter((element) => ['auto', 'scroll'].includes(style(element).overflowX))) {
                if (container.scrollWidth <= container.clientWidth + 1 || container.matches('input, select') || scrollsByDesign(container) || excluded(container)) {
                    continue;
                }

                // The outermost element wider than the container is what makes it scroll.
                const content = [...container.querySelectorAll('*')].filter(shown)
                    .find((element) => element.getBoundingClientRect().width > container.clientWidth + 1);
                const what = content
                    ? `${content.tagName === 'TABLE' ? 'The table' : 'The element'} ${name(content)} is ${px(content.getBoundingClientRect().width)} wide`
                    : `The content is ${container.scrollWidth}px wide`;

                problems.push(`${what} in the ${container.clientWidth}px scroll container ${selector(container)}, so the container scrolls sideways.`);
            }

            for (const table of all.filter((element) => element.tagName === 'TABLE' && ! excluded(element))) {
                const edges = table.getBoundingClientRect();

                for (let box = table.parentElement; box && box !== document.body; box = box.parentElement) {
                    const { display, overflowX, borderLeftWidth, borderRightWidth } = style(box);
                    const rect = box.getBoundingClientRect();

                    // An inline box or one without a box of its own can't contain the table.
                    if (display === 'contents' || display === 'inline') {
                        continue;
                    }

                    // Scroll containers are measured above; one that clips cuts the table off.
                    if (overflowX !== 'visible') {
                        if (['hidden', 'clip'].includes(overflowX) && box.scrollWidth > box.clientWidth + 1) {
                            problems.push(`The table ${name(table)} is ${px(edges.width)} wide and cut off by its ${box.clientWidth}px container ${selector(box)}.`);
                        }

                        break;
                    }

                    // A table may reach into its container's padding, as a full-bleed table does.
                    const past = Math.max(edges.right - (rect.right - parseFloat(borderRightWidth)), (rect.left + parseFloat(borderLeftWidth)) - edges.left);

                    if (past > 1) {
                        problems.push(`The table ${name(table)} is ${px(edges.width)} wide and runs ${px(past)} past its ${box.clientWidth}px container ${selector(box)}.`);

                        break;
                    }
                }
            }

            for (const badge of all.filter((element) => element.matches('.fi-badge') && ! excluded(element))) {
                const cell = badge.closest('td, th, .fi-ta-cell, .fi-in-entry-content-ctn');
                const past = cell ? badge.getBoundingClientRect().right - cell.getBoundingClientRect().right : 0;

                if (past > 1) {
                    problems.push(`The badge ${name(badge)} runs ${px(past)} past its cell.`);
                }
            }

            if (tapTargets && matchMedia('(pointer: coarse)').matches) {
                const controls = 'button, summary, select, textarea, [role="button"], a.fi-btn, a.fi-link, input:not([type="hidden"], [type="checkbox"], [type="radio"])';

                for (const control of all.filter((element) => element.matches(controls) && ! element.closest('.sr-only') && ! excluded(element))) {
                    // A Filament field's box is its wrapper, which can be taller than its input.
                    const height = Math.round((control.closest('.fi-input-wrp') ?? control).getBoundingClientRect().height);

                    if (height < 44) {
                        problems.push(`The control ${name(control)} is ${height}px tall, under the 44px a finger needs.`);
                    }
                }
            }

            return [...new Set(problems)];
        }
        JS;

    /**
     * Visit a path in a browser this many pixels wide.
     *
     * Below 1024 pixels the browser has a touch screen, and below 768 pixels it
     * acts as a phone, reading the page's viewport meta tag as a phone does.
     */
    public static function visit(string $path, int $width): PendingAwaitablePage
    {
        return Expectations::visit($path, [
            'viewport' => ['width' => $width, 'height' => 900],
            'deviceScaleFactor' => 1,
            'isMobile' => $width < 768,
            'hasTouch' => $width < 1024,
        ]);
    }

    /**
     * Get everything wrong with the page's layout at its current width.
     *
     * @param  bool  $tapTargets  Whether a touch screen's controls must be 44px tall
     * @param  list<string>  $exclude  CSS selectors of elements the checks leave out
     * @return list<string>
     */
    public static function problems(AwaitableWebpage|PendingAwaitablePage $page, bool $tapTargets = false, array $exclude = []): array
    {
        $playwright = Health::playwright($page);
        $playwright->waitForLoadState('networkidle');

        // A Filament table that loads its rows late is measured with them.
        Health::waitUntil($page, "() => document.querySelector('.fi-ta-content-loading') === null");

        $problems = $playwright->evaluate(self::SCRIPT, [$tapTargets, $exclude]);

        return Expectations::strings(is_array($problems) ? $problems : []);
    }

    /**
     * Get the width of the page's screen, in CSS pixels.
     */
    public static function width(AwaitableWebpage|PendingAwaitablePage $page): int
    {
        $width = Health::playwright($page)->evaluate('() => document.documentElement.clientWidth');

        return is_int($width) ? $width : 0;
    }

    /**
     * @param  list<string>  $problems
     */
    public static function describe(string $path, int $width, array $problems): string
    {
        return sprintf("Expected [%s] to fit a %dpx screen.\n- %s", $path, $width, implode("\n- ", $problems));
    }
}
