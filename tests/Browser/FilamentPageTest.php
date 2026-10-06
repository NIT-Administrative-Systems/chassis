<?php

declare(strict_types=1);

use Northwestern\SysDev\Chassis\Testing\Browser\ClientErrors;
use Northwestern\SysDev\Chassis\Testing\Browser\FilamentPage;
use PHPUnit\Framework\ExpectationFailedException;

beforeEach(fn () => $this->signIn());

it('opens the user menu', function () {
    $page = visit('/admin/widgets');

    FilamentPage::openUserMenu($page);

    $page->assertVisible('.fi-user-menu .fi-dropdown-panel');
});

it('confirms a modal and sees the notification', function () {
    $page = visit('/admin/widgets');
    ClientErrors::capture($page);

    $page->click('Archive All');
    FilamentPage::confirmModal($page);

    FilamentPage::assertNotified($page, 'Widgets Archived');
});

it('cancels a modal', function () {
    $page = visit('/admin/widgets');

    $page->click('Archive All');
    FilamentPage::cancelModal($page);

    expect($page->script("document.querySelectorAll('.fi-no-notification').length"))->toBe(0);
});

it('fails when the notification never shows', function () {
    $page = visit('/admin/widgets');

    FilamentPage::assertNotified($page, 'Widgets Archived');
})->throws(ExpectationFailedException::class, 'Expected a notification titled [Widgets Archived].');

it("finds a field's validation error by its label", function () {
    $page = visit('/admin/widgets/create');

    $page->type('#form\\.name', 'Gadget');
    FilamentPage::press($page, 'Create');

    FilamentPage::assertFieldError($page, 'Name');
    FilamentPage::assertFieldError($page, 'Name', 'The name field must start with one of the following: W.');
});

it('fails when the field shows a different error', function () {
    $page = visit('/admin/widgets/create');
    $page->type('#form\\.name', 'Gadget');
    FilamentPage::press($page, 'Create');
    FilamentPage::assertFieldError($page, 'Name');

    FilamentPage::assertFieldError($page, 'Name', 'The name is taken.');
})->throws(ExpectationFailedException::class, 'Expected the [Name] field to show the error [The name is taken.].');
