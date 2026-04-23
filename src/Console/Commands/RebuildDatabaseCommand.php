<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Northwestern\SysDev\Chassis\Console\Concerns\RunsSteps;
use Throwable;

/**
 * Rebuilds the database from scratch with fresh migrations and seeders.
 *
 * Consuming apps typically add one or two app-specific steps (demo seeders,
 * IDE helper generation, cache warmers) after the base flow. The preferred
 * extension point is `appendSteps()`:
 *
 * ```php
 * class RebuildDatabaseCommand extends \Northwestern\SysDev\Chassis\Console\Commands\RebuildDatabaseCommand
 * {
 *     protected function appendSteps(): array
 *     {
 *         return [
 *             'Seeding demo data' => fn () => $this->callSilently('db:seed', ['--class' => 'DemoSeeder', '--force' => true]),
 *             'Generating IDE helpers' => fn () => $this->callSilently('ide-helper:models', ['-N' => true]),
 *         ];
 *     }
 * }
 * ```
 *
 * For full control (removing a base step, interleaving, etc.), override
 * `steps()` and assemble the list directly — `baseSteps()` returns the
 * chassis defaults so you can compose instead of duplicating.
 */
class RebuildDatabaseCommand extends Command
{
    use RunsSteps;

    protected $signature = 'db:rebuild';

    protected $description = 'Rebuild the database with fresh migrations and seeders';

    public function handle(): int
    {
        if (App::isProduction()) {
            $this->components->error('This command cannot be run in production.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Rebuilding Database');

        foreach ($this->steps() as $name => $callback) {
            if (! $this->runStep($name, $callback)) {
                $this->displaySummary();

                return self::FAILURE;
            }
        }

        $this->displaySummary();

        if ($this->allPassed()) {
            $this->displayPostBuildInfo();
        }

        return $this->allPassed() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Display additional information after a successful rebuild.
     *
     * Override to show app-specific details (queue size, demo tokens, etc.).
     */
    protected function displayPostBuildInfo(): void
    {
        //
    }

    protected function successMessage(): string
    {
        return 'Database rebuild complete';
    }

    /**
     * The ordered list of rebuild steps.
     *
     * By default, chassis's base steps followed by anything from appendSteps().
     * Override this method only when you need to remove a base step or
     * interleave app steps inside the base flow.
     *
     * @return array<string, callable(): mixed>
     */
    protected function steps(): array
    {
        return array_merge($this->baseSteps(), $this->appendSteps());
    }

    /**
     * The baseline rebuild steps provided by chassis.
     *
     * Use this from a full `steps()` override when you want to compose with
     * (rather than replace) the default flow.
     *
     * @return array<string, callable(): mixed>
     */
    protected function baseSteps(): array
    {
        return [
            'Clearing cache' => $this->clearCache(...),
            'Clearing queue' => fn () => $this->callSilently('queue:clear', ['--force' => true]),
            'Clearing schedule cache' => fn () => $this->callSilently('schedule:clear-cache'),
            'Running migrations' => fn () => $this->callSilently('migrate:fresh', ['--force' => true]),
            'Seeding database' => fn () => $this->callSilently('db:seed', ['--force' => true]),
        ];
    }

    /**
     * Extra steps to run after the base rebuild flow.
     *
     * Override in a subclass to add app-specific steps. This is the
     * preferred extension point — overriding this instead of `steps()`
     * means you pick up any new base steps chassis adds in future versions.
     *
     * @return array<string, callable(): mixed>
     */
    protected function appendSteps(): array
    {
        return [];
    }

    /**
     * Clear the application cache, ignoring errors if the cache table doesn't exist.
     */
    protected function clearCache(): void
    {
        try {
            $this->callSilently('cache:clear');
        } catch (Throwable) {
            // Ignore - database cache table may not exist yet
        }
    }
}
