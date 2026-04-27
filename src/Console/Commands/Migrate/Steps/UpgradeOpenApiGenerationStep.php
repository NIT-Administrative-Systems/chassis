<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps;

use Illuminate\Support\Facades\File;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationContext;

/**
 * Ensure migrated apps keep scanning chassis OpenAPI annotations.
 *
 * Starter apps commonly generate `docs/schemas/api-schema.yaml` by scanning
 * only `app/`. After migration, some annotated schema classes live in
 * `vendor/northwestern-sysdev/chassis/src/`, so those scripts need an
 * additional scan path to avoid silently dropping shared components.
 *
 * @phpstan-type ComposerScriptValue string|list<string>
 * @phpstan-type ComposerScripts array<string, ComposerScriptValue>
 * @phpstan-type ComposerConfig array{scripts?: ComposerScripts}&array<string, mixed>
 */
class UpgradeOpenApiGenerationStep extends AbstractMigrationStep
{
    private const string COMPOSER_PATH = 'composer.json';

    private const string CHASSIS_SCAN_PATH = 'vendor/northwestern-sysdev/chassis/src/';

    /** @var list<string> */
    private const array OPENAPI_SCRIPT_NAMES = [
        'openapi:generate',
        'openapi:generate:v1',
    ];

    public function label(): string
    {
        return 'Upgrading OpenAPI generation scripts...';
    }

    public function run(MigrationContext $context): void
    {
        $absolutePath = base_path(self::COMPOSER_PATH);

        if (! File::exists($absolutePath)) {
            return;
        }

        $decodedComposer = json_decode(File::get($absolutePath), true);

        if (! is_array($decodedComposer)) {
            return;
        }

        /** @var ComposerConfig $composerConfig */
        $composerConfig = $decodedComposer;
        $updatedComposerConfig = $this->addChassisScanPathToOpenApiScripts($composerConfig);

        if ($updatedComposerConfig === $composerConfig) {
            return;
        }

        $encodedComposer = json_encode($updatedComposerConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encodedComposer === false) {
            return;
        }

        if (! $context->isDryRun) {
            File::put($absolutePath, $encodedComposer . PHP_EOL);
        }

        $this->markFileModified($context);

        foreach (self::OPENAPI_SCRIPT_NAMES as $scriptName) {
            $originalScript = $composerConfig['scripts'][$scriptName] ?? null;
            $updatedScript = $updatedComposerConfig['scripts'][$scriptName] ?? null;

            if ($originalScript === $updatedScript) {
                continue;
            }

            $this->success($context, "composer.json updated {$scriptName} to scan chassis OpenAPI annotations");
            $this->recordChange($context, self::COMPOSER_PATH, 1, "Updated {$scriptName} to scan chassis OpenAPI annotations");
        }
    }

    /**
     * Append the chassis source tree to the app's OpenAPI scripts when they
     * currently scan only `app/`.
     *
     * @param  ComposerConfig  $composerConfig
     * @return ComposerConfig
     */
    private function addChassisScanPathToOpenApiScripts(array $composerConfig): array
    {
        $composerScripts = $composerConfig['scripts'] ?? null;

        if (! is_array($composerScripts)) {
            return $composerConfig;
        }

        $wasUpdated = false;

        foreach (self::OPENAPI_SCRIPT_NAMES as $scriptName) {
            $scriptCommand = $composerScripts[$scriptName] ?? null;

            if (! is_string($scriptCommand)) {
                continue;
            }

            $updatedScriptCommand = $this->appendChassisScanPath($scriptCommand);

            if ($updatedScriptCommand === $scriptCommand) {
                continue;
            }

            $composerScripts[$scriptName] = $updatedScriptCommand;
            $wasUpdated = true;
        }

        if (! $wasUpdated) {
            return $composerConfig;
        }

        $composerConfig['scripts'] = $composerScripts;

        return $composerConfig;
    }

    /**
     * Rewrite `vendor/bin/openapi app/ ...` scripts to scan chassis too.
     *
     * Leaves scripts unchanged if they already include the chassis path or do
     * not match the expected `app/`-based scan pattern.
     */
    private function appendChassisScanPath(string $scriptCommand): string
    {
        if (! str_contains($scriptCommand, 'vendor/bin/openapi')) {
            return $scriptCommand;
        }

        if (str_contains($scriptCommand, self::CHASSIS_SCAN_PATH)) {
            return $scriptCommand;
        }

        if (! preg_match('/(vendor\/bin\/openapi\s+)app\//', $scriptCommand)) {
            return $scriptCommand;
        }

        return preg_replace(
            '/(vendor\/bin\/openapi\s+)app\//',
            '${1}app/ ' . self::CHASSIS_SCAN_PATH,
            $scriptCommand,
            1
        ) ?? $scriptCommand;
    }
}
