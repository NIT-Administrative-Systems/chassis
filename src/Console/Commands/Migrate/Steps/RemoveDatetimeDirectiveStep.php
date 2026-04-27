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
    private const string VIEW_SERVICE_PROVIDER_PATH = 'app/Providers/ViewServiceProvider.php';

    public function label(): string
    {
        return 'Removing @datetime directive from ViewServiceProvider...';
    }

    public function run(MigrationContext $context): void
    {
        $relativePath = self::VIEW_SERVICE_PROVIDER_PATH;
        $absolutePath = base_path($relativePath);

        if (! File::exists($absolutePath)) {
            $this->skip($context, "{$relativePath} (file not found, skipped)");

            return;
        }

        $providerSource = File::get($absolutePath);

        // Skip if the directive doesn't exist
        if (! str_contains($providerSource, "Blade::directive('datetime'")) {
            $this->skip($context, "{$relativePath} (@datetime directive already removed, skipped)");

            return;
        }

        // Remove the two lines: the $dateTimeFormatter resolve and the Blade::directive call
        $providerSource = preg_replace(
            '/\n?\s*\$dateTimeFormatter\s*=\s*resolve\([^)]+\);\s*\n/',
            "\n",
            $providerSource,
        );

        $providerSource = preg_replace(
            '/\s*Blade::directive\(\'datetime\'[^;]+;\s*\n/',
            '',
            (string) $providerSource,
        );

        // Clean up the DateTimeFormatter use statement if present
        $providerSource = preg_replace(
            '/use\s+[^\n]*DateTimeFormatter[^\n]*;\n/',
            '',
            (string) $providerSource,
        );

        // Clean up the Blade use statement if no other Blade references remain
        if (! str_contains((string) $providerSource, 'Blade::')) {
            $providerSource = preg_replace(
                '/use\s+Illuminate\\\\Support\\\\Facades\\\\Blade;\n/',
                '',
                (string) $providerSource,
            );
        }

        // Keep the empty boot method formatted predictably after removing the
        // last statement from the body.
        $providerSource = preg_replace(
            '/public function boot\(\): void\s*\{\s*\}/',
            "public function boot(): void\n    {\n    }",
            (string) $providerSource,
        );

        if (! $context->isDryRun) {
            File::put($absolutePath, (string) $providerSource);
        }

        $this->markFileModified($context);
        $this->success($context, "{$relativePath} (removed @datetime directive)");
    }
}
