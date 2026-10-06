<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Testing\Browser;

use LogicException;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;

/**
 * Waits for Livewire to finish talking to the server.
 *
 * Pest's assertions retry until they pass, but an assertion that already passes before a
 * request returns, such as "this text is absent", proves nothing. A debounced field
 * (`wire:model.live.debounce.500ms`) doesn't send its request until the delay passes, so a
 * submit right after typing can send the old value.
 */
final class LivewireRequests
{
    /**
     * Wait until no Livewire request is in flight and the browser has painted the result.
     *
     * Requests are counted by the script {@see ClientErrors::capture()} adds before the page
     * loads; Livewire 4 doesn't run request hooks added after it starts. Capture before
     * interacting with the page, directly or through any health expectation.
     *
     * @param  int  $debounce  Milliseconds to wait first, for a debounced field's delay
     *
     * @throws LogicException When the page isn't capturing
     */
    public static function settle(AwaitableWebpage|PendingAwaitablePage $page, int $debounce = 0): void
    {
        $playwright = Health::playwright($page);

        if ($playwright->evaluate('() => window.__chassis?.livewireTracked === true') !== true) {
            throw new LogicException(
                'Livewire requests are only counted on a page that is capturing. Call ClientErrors::capture($page) '
                . 'before interacting with it.',
            );
        }

        $playwright->evaluate(
            'async (delay) => { await new Promise((resolve) => setTimeout(resolve, delay)); }',
            $debounce,
        );

        Health::waitUntil($page, '() => window.__chassis.livewirePending <= 0');

        $playwright->evaluate('async () => { await new Promise((resolve) => requestAnimationFrame(() => resolve())); }');
    }
}
