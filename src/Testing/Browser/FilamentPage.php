<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Testing\Browser;

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\TestSuite;
use Throwable;

/**
 * Drives the parts of a Filament panel that tests touch most, through the markup Filament
 * renders. Written against Filament 5.
 */
final class FilamentPage
{
    /**
     * Click the button with this accessible name.
     *
     * The plugin's `press()` clicks the first element whose text matches, which on a Filament
     * page is often a breadcrumb or heading with the same word ("Create").
     */
    public static function press(AwaitableWebpage|PendingAwaitablePage $page, string $button): void
    {
        Health::playwright($page)->getByRole('button', ['name' => $button, 'exact' => true])->click();
    }

    /**
     * Open the panel's user menu and wait for it to show.
     */
    public static function openUserMenu(AwaitableWebpage|PendingAwaitablePage $page): void
    {
        $playwright = Health::playwright($page);

        $playwright->locator('.fi-user-menu-trigger')->first()->click();

        self::waitFor(
            $page,
            <<<'JS'
                () => {
                    const trigger = document.querySelector('.fi-user-menu-trigger[aria-expanded="true"]');
                    const panel = trigger && document.getElementById(trigger.getAttribute('aria-controls'));

                    return panel !== null && panel.checkVisibility();
                }
                JS,
            null,
            'Expected the user menu to open.',
        );
    }

    /**
     * Wait for a notification with this title.
     */
    public static function assertNotified(AwaitableWebpage|PendingAwaitablePage $page, string $title): void
    {
        self::waitFor(
            $page,
            "(title) => [...document.querySelectorAll('.fi-no-notification-title')].some((element) => element.textContent.trim() === title)",
            $title,
            "Expected a notification titled [{$title}].",
        );
    }

    /**
     * Click the open modal's submit action, which confirms or saves, and wait for the modal to
     * close.
     */
    public static function confirmModal(AwaitableWebpage|PendingAwaitablePage $page): void
    {
        self::clickModalAction($page, '.fi-btn[type="submit"]');
    }

    /**
     * Click the open modal's Cancel action and wait for the modal to close.
     */
    public static function cancelModal(AwaitableWebpage|PendingAwaitablePage $page): void
    {
        self::clickModalAction($page, '.fi-btn:not([type="submit"])');
    }

    /**
     * Wait for the field with this label to show a validation error, or this error when given.
     */
    public static function assertFieldError(AwaitableWebpage|PendingAwaitablePage $page, string $label, ?string $message = null): void
    {
        self::waitFor(
            $page,
            <<<'JS'
                ([label, message]) => [...document.querySelectorAll('.fi-fo-field')].some((field) => {
                    const content = field.querySelector('.fi-fo-field-label-content');

                    if (! content || content.textContent.replace(/\*\s*$/, '').trim() !== label) {
                        return false;
                    }

                    return [...field.querySelectorAll('.fi-fo-field-wrp-error-message')]
                        .some((error) => message === null || error.textContent.trim() === message);
                })
                JS,
            [$label, $message],
            $message === null
                ? "Expected the [{$label}] field to show a validation error."
                : "Expected the [{$label}] field to show the error [{$message}].",
        );
    }

    /**
     * Filament puts Cancel before the submit action, as a plain button.
     */
    private static function clickModalAction(AwaitableWebpage|PendingAwaitablePage $page, string $action): void
    {
        Health::playwright($page)
            ->locator('.fi-modal.fi-modal-open .fi-modal-footer-actions ' . $action)
            ->last()
            ->click();

        self::waitFor(
            $page,
            "() => document.querySelectorAll('.fi-modal.fi-modal-open').length === 0",
            null,
            'Expected the modal to close.',
        );
    }

    private static function waitFor(AwaitableWebpage|PendingAwaitablePage $page, string $condition, mixed $argument, string $failure): void
    {
        try {
            Health::waitUntil($page, $condition, $argument);
        } catch (Throwable $exception) {
            Health::fail($page, $failure . ' ' . $exception->getMessage());
        }

        TestSuite::getInstance()->test?->addToAssertionCount(1);
    }
}
