<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps;

use Illuminate\Support\Facades\File;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationContext;

/**
 * Remove the @datetime Blade directive registration from ViewServiceProvider.
 */
class RemoveDatetimeDirectiveStep extends AbstractMigrationStep
{
    public function label(): string
    {
        return 'Removing @datetime directive from ViewServiceProvider...';
    }

    public function run(MigrationContext $context): void
    {
        $path = 'app/Providers/ViewServiceProvider.php';
        $fullPath = base_path($path);

        if (! File::exists($fullPath)) {
            $this->skip($context, "{$path} (file not found, skipped)");

            return;
        }

        $code = File::get($fullPath);

        // Skip if the directive doesn't exist
        if (! str_contains($code, "Blade::directive('datetime'")) {
            $this->skip($context, "{$path} (@datetime directive already removed, skipped)");

            return;
        }

        // Remove the two lines: the $dateTimeFormatter resolve and the Blade::directive call
        $code = preg_replace(
            '/\n?\s*\$dateTimeFormatter\s*=\s*resolve\([^)]+\);\s*\n/',
            "\n",
            $code,
        );

        $code = preg_replace(
            '/\s*Blade::directive\(\'datetime\'[^;]+;\s*\n/',
            "        //\n",
            (string) $code,
        );

        // Clean up the DateTimeFormatter use statement if present
        $code = preg_replace(
            '/use\s+[^\n]*DateTimeFormatter[^\n]*;\n/',
            '',
            (string) $code,
        );

        // Clean up the Blade use statement if no other Blade references remain
        if (! str_contains((string) $code, 'Blade::')) {
            $code = preg_replace(
                '/use\s+Illuminate\\\\Support\\\\Facades\\\\Blade;\n/',
                '',
                (string) $code,
            );
        }

        if (! $context->isDryRun) {
            File::put($fullPath, (string) $code);
        }

        $this->markFileModified($context);
        $this->success($context, "{$path} (removed @datetime directive)");
    }
}
