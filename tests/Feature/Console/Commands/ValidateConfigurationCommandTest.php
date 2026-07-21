<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Console\Commands;

use Illuminate\Contracts\Console\Kernel;
use Mockery\MockInterface;
use Northwestern\SysDev\Chassis\Console\Commands\ValidateConfigurationCommand;
use Northwestern\SysDev\Chassis\Services\ConfigValidatorResolver;
use Northwestern\SysDev\Chassis\Tests\TestCase;

/*
 * ValidateConfigurationCommand is excluded from coverage in phpunit.xml
 * (operational command), so this test intentionally has no #[CoversClass].
 */
class ValidateConfigurationCommandTest extends TestCase
{
    public function test_uses_resolver_default_paths_by_default(): void
    {
        $this->mock(ConfigValidatorResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('discover')->once()->with(null)->andReturn([]);
        });

        $this->artisan('config:validate')->assertExitCode(0);
    }

    public function test_subclasses_can_override_discovery_paths(): void
    {
        $this->mock(ConfigValidatorResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('discover')
                ->once()
                ->with('/custom/validators/path')
                ->andReturn([]);
        });

        $this->app->make(Kernel::class)->registerCommand(new CustomPathValidateConfigurationCommand());

        $this->artisan('config:validate:custom-path')->assertExitCode(0);
    }

    public function test_runs_validators_discovered_from_custom_path(): void
    {
        $this->app->make(Kernel::class)->registerCommand(new FixturePathValidateConfigurationCommand());

        $this->artisan('config:validate:fixture-path')
            ->expectsOutputToContain('Cache Store')
            ->expectsOutputToContain('Database Connection')
            ->assertExitCode(0);
    }
}

class CustomPathValidateConfigurationCommand extends ValidateConfigurationCommand
{
    protected $signature = 'config:validate:custom-path';

    protected function discoverPaths(): string|array|null
    {
        return '/custom/validators/path';
    }
}

class FixturePathValidateConfigurationCommand extends ValidateConfigurationCommand
{
    protected $signature = 'config:validate:fixture-path';

    protected function discoverPaths(): string|array|null
    {
        return __DIR__ . '/../../../Fixtures/Validators';
    }
}
