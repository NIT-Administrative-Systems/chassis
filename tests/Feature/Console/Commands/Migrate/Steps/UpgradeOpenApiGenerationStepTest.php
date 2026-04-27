<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Console\Commands\Migrate\Steps;

use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps\UpgradeOpenApiGenerationStep;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use ReflectionMethod;

/**
 * @phpstan-type ComposerConfig array{scripts: array<string, string>} & array<string, mixed>
 */
class UpgradeOpenApiGenerationStepTest extends TestCase
{
    private UpgradeOpenApiGenerationStep $step;

    protected function setUp(): void
    {
        parent::setUp();

        $this->step = new UpgradeOpenApiGenerationStep();
    }

    public function test_adds_chassis_scan_path_to_openapi_scripts(): void
    {
        $composerConfig = [
            'scripts' => [
                'openapi:generate' => '@php ./vendor/bin/openapi app/',
                'openapi:generate:v1' => '@php ./vendor/bin/openapi app/ --exclude Api/V2 --output docs/schemas/api-schema.yaml',
            ],
        ];

        $updatedComposerConfig = $this->transformComposerConfig($composerConfig);

        $this->assertSame(
            '@php ./vendor/bin/openapi app/ vendor/northwestern-sysdev/chassis/src/',
            $updatedComposerConfig['scripts']['openapi:generate'],
        );
        $this->assertSame(
            '@php ./vendor/bin/openapi app/ vendor/northwestern-sysdev/chassis/src/ --exclude Api/V2 --output docs/schemas/api-schema.yaml',
            $updatedComposerConfig['scripts']['openapi:generate:v1'],
        );
    }

    public function test_is_idempotent_when_chassis_path_is_already_present(): void
    {
        $composerConfig = [
            'scripts' => [
                'openapi:generate' => '@php ./vendor/bin/openapi app/ vendor/northwestern-sysdev/chassis/src/',
                'openapi:generate:v1' => '@php ./vendor/bin/openapi app/ vendor/northwestern-sysdev/chassis/src/ --exclude Api/V2 --output docs/schemas/api-schema.yaml',
            ],
        ];

        $updatedComposerConfig = $this->transformComposerConfig($composerConfig);

        $this->assertSame($composerConfig, $updatedComposerConfig);
    }

    public function test_ignores_non_openapi_scripts(): void
    {
        $composerConfig = [
            'scripts' => [
                'test' => 'vendor/bin/pest',
                'format:php' => 'vendor/bin/pint --ansi',
            ],
        ];

        $updatedComposerConfig = $this->transformComposerConfig($composerConfig);

        $this->assertSame($composerConfig, $updatedComposerConfig);
    }

    public function test_ignores_openapi_scripts_that_do_not_scan_app_directory(): void
    {
        $composerConfig = [
            'scripts' => [
                'openapi:generate' => '@php ./vendor/bin/openapi routes/',
            ],
        ];

        $updatedComposerConfig = $this->transformComposerConfig($composerConfig);

        $this->assertSame($composerConfig, $updatedComposerConfig);
    }

    /**
     * @param  ComposerConfig  $composerConfig
     * @return ComposerConfig
     */
    private function transformComposerConfig(array $composerConfig): array
    {
        $method = new ReflectionMethod($this->step, 'addChassisScanPathToOpenApiScripts');
        $method->setAccessible(true);

        /** @var ComposerConfig */
        return $method->invoke($this->step, $composerConfig);
    }
}
