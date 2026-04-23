<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Console\Commands;

use Illuminate\Support\Facades\App;
use Northwestern\SysDev\Chassis\Console\Commands\RebuildDatabaseCommand;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use ReflectionMethod;

class RebuildDatabaseCommandTest extends TestCase
{
    public function test_refuses_to_run_in_production(): void
    {
        App::shouldReceive('isProduction')->andReturn(true);

        $this->artisan('db:rebuild')
            ->assertFailed();
    }

    public function test_command_is_registered_with_correct_signature(): void
    {
        $this->artisan('db:rebuild --help')
            ->assertSuccessful();
    }

    public function test_base_steps_returns_canonical_rebuild_flow(): void
    {
        $steps = $this->invokeProtectedMethod(new RebuildDatabaseCommand(), 'baseSteps');

        $this->assertSame([
            'Clearing cache',
            'Clearing queue',
            'Clearing schedule cache',
            'Running migrations',
            'Seeding database',
        ], array_keys($steps));
    }

    public function test_default_append_steps_is_empty(): void
    {
        $steps = $this->invokeProtectedMethod(new RebuildDatabaseCommand(), 'appendSteps');

        $this->assertSame([], $steps);
    }

    public function test_steps_merges_base_and_append(): void
    {
        $command = new class extends RebuildDatabaseCommand
        {
            protected function appendSteps(): array
            {
                return [
                    'App custom step' => fn () => null,
                ];
            }
        };

        $steps = $this->invokeProtectedMethod($command, 'steps');

        $this->assertSame([
            'Clearing cache',
            'Clearing queue',
            'Clearing schedule cache',
            'Running migrations',
            'Seeding database',
            'App custom step',
        ], array_keys($steps));
    }

    public function test_steps_can_be_fully_overridden_for_custom_ordering(): void
    {
        $command = new class extends RebuildDatabaseCommand
        {
            protected function steps(): array
            {
                // Interleave an app step between base steps.
                $base = $this->baseSteps();

                return [
                    'Clearing cache' => $base['Clearing cache'],
                    'Pre-migration check' => fn () => null,
                    'Running migrations' => $base['Running migrations'],
                    'Seeding database' => $base['Seeding database'],
                ];
            }
        };

        $steps = $this->invokeProtectedMethod($command, 'steps');

        $this->assertSame([
            'Clearing cache',
            'Pre-migration check',
            'Running migrations',
            'Seeding database',
        ], array_keys($steps));
    }

    /**
     * @return array<string, callable(): mixed>
     */
    private function invokeProtectedMethod(RebuildDatabaseCommand $command, string $method): array
    {
        $reflection = new ReflectionMethod($command, $method);
        /** @var array<string, callable(): mixed> $result */
        $result = $reflection->invoke($command);

        return $result;
    }
}
