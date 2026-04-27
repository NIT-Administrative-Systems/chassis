<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Console\Commands\Migrate\Steps;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationContext;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps\UpgradeOpenApiGenerationStep;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Illuminate\Console\OutputStyle;

class UpgradeOpenApiGenerationStepTest extends TestCase
{
    private string $composerPath;

    private string $originalComposerJson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->composerPath = base_path('composer.json');
        $this->originalComposerJson = File::get($this->composerPath);
    }

    protected function tearDown(): void
    {
        File::put($this->composerPath, $this->originalComposerJson);

        parent::tearDown();
    }

    public function test_adds_chassis_scan_path_to_openapi_scripts(): void
    {
        File::put($this->composerPath, <<<'JSON'
        {
            "scripts": {
                "openapi:generate": "@php ./vendor/bin/openapi app/",
                "openapi:generate:v1": "@php ./vendor/bin/openapi app/ --exclude Api/V2 --output docs/schemas/api-schema.yaml"
            }
        }
        JSON);

        $context = $this->makeContext();

        (new UpgradeOpenApiGenerationStep())->run($context);

        $updatedComposerJson = File::get($this->composerPath);

        $this->assertStringContainsString(
            '"openapi:generate": "@php ./vendor/bin/openapi app/ vendor/northwestern-sysdev/chassis/src/"',
            $updatedComposerJson,
        );
        $this->assertStringContainsString(
            '"openapi:generate:v1": "@php ./vendor/bin/openapi app/ vendor/northwestern-sysdev/chassis/src/ --exclude Api/V2 --output docs/schemas/api-schema.yaml"',
            $updatedComposerJson,
        );
        $this->assertSame(1, $context->filesModified);
    }

    public function test_is_idempotent_when_chassis_path_is_already_present(): void
    {
        $composerJson = <<<'JSON'
        {
            "scripts": {
                "openapi:generate": "@php ./vendor/bin/openapi app/ vendor/northwestern-sysdev/chassis/src/",
                "openapi:generate:v1": "@php ./vendor/bin/openapi app/ vendor/northwestern-sysdev/chassis/src/ --exclude Api/V2 --output docs/schemas/api-schema.yaml"
            }
        }
        JSON;

        File::put($this->composerPath, $composerJson);

        $context = $this->makeContext();

        (new UpgradeOpenApiGenerationStep())->run($context);

        $this->assertSame($composerJson, File::get($this->composerPath));
        $this->assertSame(0, $context->filesModified);
    }

    public function test_dry_run_reports_change_without_writing_file(): void
    {
        $composerJson = <<<'JSON'
        {
            "scripts": {
                "openapi:generate": "@php ./vendor/bin/openapi app/"
            }
        }
        JSON;

        File::put($this->composerPath, $composerJson);

        $context = new MigrationContext(isDryRun: true, command: $this->makeSilentCommand());

        (new UpgradeOpenApiGenerationStep())->run($context);

        $this->assertSame($composerJson, File::get($this->composerPath));
        $this->assertSame(1, $context->filesModified);
    }

    private function makeContext(): MigrationContext
    {
        return new MigrationContext(
            isDryRun: false,
            command: $this->makeSilentCommand(),
        );
    }

    private function makeSilentCommand(): Command
    {
        $command = new class extends Command
        {
            protected $signature = 'test:migrate-openapi-step';
        };

        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));

        return $command;
    }
}
