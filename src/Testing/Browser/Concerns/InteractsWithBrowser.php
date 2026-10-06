<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Testing\Browser\Concerns;

use Illuminate\Support\Facades\Exceptions;

/**
 * Sets up a browser test case for the Chassis browser expectations: fakes exceptions so
 * `toHaveNoServerErrors()` sees what the application reported while serving the browser.
 *
 * Laravel calls `setUpInteractsWithBrowser()` after the application boots.
 */
trait InteractsWithBrowser
{
    protected function setUpInteractsWithBrowser(): void
    {
        Exceptions::fake();
    }
}
