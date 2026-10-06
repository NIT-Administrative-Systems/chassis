<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Browser;

use Illuminate\Support\Facades\Route;

/**
 * Plain HTML pages registered for one browser test.
 */
final class Pages
{
    public static function serve(string $path, string $body, string $head = ''): string
    {
        Route::get($path, static fn (): string => <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Test Page</title>
                {$head}
            </head>
            <body>
                <main>
                    <h1>Test Page</h1>
                    {$body}
                </main>
            </body>
            </html>
            HTML);

        return $path;
    }
}
