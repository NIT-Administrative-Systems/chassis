<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Testing\Browser;

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Exceptions\BrowserExpectationFailedException;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Playwright\Playwright;
use PHPUnit\Framework\ExpectationFailedException;
use Throwable;
use UnexpectedValueException;

/**
 * Checks that a page works for the person using it: no exception on the server, no error in
 * the browser, no broken image, and no accessibility violation.
 */
final class Health
{
    /**
     * Everything wrong with the page, by kind; empty when it's healthy.
     *
     * @param  list<string>  $exclude  CSS selectors axe leaves out on this page
     * @param  int  $serverErrorsSince  How many reported exceptions belong to earlier pages
     * @return array<string, list<string>>
     */
    public static function problems(AwaitableWebpage|PendingAwaitablePage $page, array $exclude = [], int $serverErrorsSince = 0): array
    {
        ClientErrors::capture($page);

        $problems = [
            'Accessibility violations' => array_map(
                static fn (AccessibilityViolation $violation): string => $violation->describe(),
                Accessibility::violations($page, $exclude),
            ),
            'Broken images' => array_map(
                static fn (string $source): string => '- ' . $source,
                array_values(self::playwright($page)->brokenImages()),
            ),
            'Browser errors' => array_map(
                static fn (string $error): string => '- ' . $error,
                ClientErrors::of($page),
            ),
            'Server errors' => array_map(
                ServerErrors::describe(...),
                array_slice(ServerErrors::reported(), $serverErrorsSince),
            ),
        ];

        return array_filter($problems, static fn (array $lines): bool => $lines !== []);
    }

    /**
     * @param  array<string, list<string>>  $problems
     */
    public static function describe(string $url, array $problems): string
    {
        $sections = [];

        foreach ($problems as $kind => $lines) {
            $sections[] = $kind . ":\n" . implode("\n", $lines);
        }

        return sprintf("Expected [%s] to be healthy.\n\n%s", $url, implode("\n\n", $sections));
    }

    /**
     * Fail the test with the plugin's failure details: a screenshot, the trace, and the
     * browser's console output.
     */
    public static function fail(AwaitableWebpage|PendingAwaitablePage $page, string $message): never
    {
        $failure = new ExpectationFailedException($message);

        try {
            $failure = BrowserExpectationFailedException::from(self::playwright($page), $failure);
        } catch (Throwable) {
            // The details are a convenience; the failure itself must still be reported.
        }

        throw $failure;
    }

    /**
     * The Playwright page behind a page from `visit()`, which the plugin builds on first use.
     */
    public static function playwright(AwaitableWebpage|PendingAwaitablePage $page): Page
    {
        $playwright = $page instanceof AwaitableWebpage ? $page->page() : $page->__call('page', []);

        if (! $playwright instanceof Page) {
            throw new UnexpectedValueException('Expected the page to be backed by a Playwright page.');
        }

        return $playwright;
    }

    /**
     * Wait until a JavaScript function returns a truthy value for the argument, for as long as
     * the plugin's timeout allows.
     *
     * The plugin's `waitForFunction()` returns before the condition holds, so this polls inside
     * the page instead.
     *
     * @throws ExpectationFailedException When the condition doesn't hold in time
     */
    public static function waitUntil(AwaitableWebpage|PendingAwaitablePage $page, string $function, mixed $argument = null): void
    {
        $held = self::playwright($page)->evaluate(
            sprintf(<<<'JS'
                async ([argument, timeout]) => {
                    const condition = %s;
                    const deadline = Date.now() + timeout;

                    while (Date.now() < deadline) {
                        if (await condition(argument)) {
                            return true;
                        }

                        await new Promise((resolve) => setTimeout(resolve, 50));
                    }

                    return false;
                }
                JS, $function),
            // Stop short of the plugin's own limit on the call, so this failure is the one reported.
            [$argument, max(250, Playwright::timeout() - 500)],
        );

        if ($held !== true) {
            throw new ExpectationFailedException(sprintf('Timed out after %d ms waiting for %s.', Playwright::timeout(), $function));
        }
    }

    /**
     * The path and query of a page's URL, for visiting it again.
     */
    public static function pathOf(AwaitableWebpage|PendingAwaitablePage $page): string
    {
        $url = $page->url();
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return (is_string($path) && $path !== '' ? $path : '/') . (is_string($query) ? '?' . $query : '');
    }
}
