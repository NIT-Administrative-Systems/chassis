<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
| Tests in this package are written in PHPUnit's class-based style (each file declares
| its own `class X extends TestCase`), so these `uses()` calls are effectively no-ops
| for existing tests — they only matter if someone authors Pest-style `test(...)` calls
| without picking their own base class.
|
*/

uses(
    Northwestern\SysDev\Chassis\Tests\TestCase::class,
)->in('Feature');

uses(
    PHPUnit\Framework\TestCase::class,
)->in('Unit');

/*
| Browser tests are written as Pest functions, which is how Pest's browser plugin adds visit().
| They need Playwright (`npm install`, then `npx playwright install chromium`) and run on
| their own: `vendor/bin/pest --testsuite=Browser`.
*/
uses(
    Northwestern\SysDev\Chassis\Tests\BrowserTestCase::class,
)->in('Browser');

Northwestern\SysDev\Chassis\Testing\Browser\Expectations::register();
