<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Testing\Browser;

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Playwright\Page;
use WeakMap;

/**
 * Records the browser-side errors Pest's browser plugin doesn't: `console.error()`, unhandled
 * promise rejections, and Livewire requests that fail. Alpine and Livewire report their
 * problems through `console.error()`, so a page can break without an uncaught error.
 *
 * Capturing adds a script to the page's browser context and reloads the page, so the script
 * runs before the page's own on the reload and on every later navigation. Nothing is added to
 * the application.
 */
final class ClientErrors
{
    /**
     * Also tracks Livewire requests in flight, for {@see LivewireRequests::settle()}.
     */
    public const string SCRIPT = <<<'JS'
        (() => {
            if (window.__chassis) {
                return;
            }

            const state = window.__chassis = { errors: [], livewirePending: 0, livewireTracked: false };

            const describe = (value) => {
                if (value instanceof Error) {
                    return value.message;
                }

                if (typeof value === 'object' && value !== null) {
                    try {
                        return JSON.stringify(value);
                    } catch {
                        return String(value);
                    }
                }

                return String(value);
            };

            const originalError = console.error;
            console.error = function (...args) {
                state.errors.push('console.error: ' + args.map(describe).join(' '));

                return originalError.apply(console, args);
            };

            window.addEventListener('error', (event) => {
                state.errors.push('Uncaught error: ' + (event.message || describe(event.error)));
            });

            window.addEventListener('unhandledrejection', (event) => {
                state.errors.push('Unhandled promise rejection: ' + describe(event.reason));
            });

            const trackLivewire = () => {
                if (state.livewireTracked || ! window.Livewire) {
                    return;
                }

                state.livewireTracked = true;

                window.Livewire.hook('request', ({ succeed, fail }) => {
                    state.livewirePending++;

                    succeed(() => {
                        state.livewirePending--;
                    });

                    fail(({ status }) => {
                        state.livewirePending--;
                        state.errors.push('Livewire request failed with status ' + status);
                    });
                });
            };

            trackLivewire();
            document.addEventListener('livewire:init', trackLivewire);
        })();
        JS;

    /** @var WeakMap<Page, true>|null */
    private static ?WeakMap $captured = null;

    /**
     * Start capturing on this page. The page reloads once, the first time, so errors raised
     * while it loads are captured too; call it before interacting with the page.
     */
    public static function capture(AwaitableWebpage|PendingAwaitablePage $page): void
    {
        $playwright = Health::playwright($page);
        self::$captured ??= new WeakMap();

        if (isset(self::$captured[$playwright])) {
            return;
        }

        $playwright->context()->addInitScript(self::SCRIPT);
        $page->refresh();

        self::$captured[$playwright] = true;
    }

    /**
     * The errors recorded since capturing started, capturing first if it hasn't.
     *
     * @return list<string>
     */
    public static function of(AwaitableWebpage|PendingAwaitablePage $page): array
    {
        self::capture($page);

        $errors = Health::playwright($page)->evaluate('() => window.__chassis ? window.__chassis.errors : []');

        return array_values(array_filter(
            is_array($errors) ? $errors : [],
            is_string(...),
        ));
    }
}
