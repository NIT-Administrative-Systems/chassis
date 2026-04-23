<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Console\Commands\Migrate\Steps;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationContext;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps\UpgradeRebuildDatabaseCommandStep;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpgradeRebuildDatabaseCommandStep::class)]
class UpgradeRebuildDatabaseCommandStepTest extends TestCase
{
    private string $targetPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->targetPath = base_path('app/Console/Commands/RebuildDatabaseCommand.php');
        File::ensureDirectoryExists(dirname($this->targetPath));
    }

    protected function tearDown(): void
    {
        if (File::exists($this->targetPath)) {
            File::delete($this->targetPath);
        }

        parent::tearDown();
    }

    public function test_migrates_canonical_pre_chassis_shape(): void
    {
        File::put($this->targetPath, $this->canonicalPreChassisFile());

        $context = $this->makeContext();
        (new UpgradeRebuildDatabaseCommandStep())->run($context);

        $result = File::get($this->targetPath);

        $this->assertStringContainsString(
            'extends \Northwestern\SysDev\Chassis\Console\Commands\RebuildDatabaseCommand',
            $result,
            'should rebase onto chassis RebuildDatabaseCommand',
        );

        $this->assertStringContainsString('protected function appendSteps(): array', $result);
        $this->assertStringContainsString("'Seeding demo data'", $result);
        $this->assertStringContainsString("'Generating IDE helpers'", $result);

        // Base steps are dropped.
        $this->assertStringNotContainsString("'Clearing cache'", $result);
        $this->assertStringNotContainsString("'Running migrations'", $result);
        $this->assertStringNotContainsString("'Seeding database' =>", $result);

        // Inherited methods removed.
        $this->assertStringNotContainsString('public function handle()', $result);
        $this->assertStringNotContainsString('protected function clearCache()', $result);
        $this->assertStringNotContainsString('protected function successMessage()', $result);

        // App-specific override preserved verbatim.
        $this->assertStringContainsString('protected function displayPostBuildInfo(): void', $result);
        $this->assertStringContainsString('queue:work', $result);

        // Unused imports dropped.
        $this->assertStringNotContainsString('use Illuminate\Console\Command;', $result);
        $this->assertStringNotContainsString('use Northwestern\SysDev\Chassis\Console\Concerns\RunsSteps;', $result);
        $this->assertStringNotContainsString('use Throwable;', $result);

        // Counter incremented.
        $this->assertSame(1, $context->filesScaffolded);
    }

    public function test_skips_when_file_missing(): void
    {
        $context = $this->makeContext();
        (new UpgradeRebuildDatabaseCommandStep())->run($context);

        $this->assertSame(0, $context->filesScaffolded);
        $this->assertSame([], $context->conflicts);
    }

    public function test_skips_when_already_extends_chassis_base(): void
    {
        $already = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Console\Commands;

        class RebuildDatabaseCommand extends \Northwestern\SysDev\Chassis\Console\Commands\RebuildDatabaseCommand
        {
            protected function appendSteps(): array
            {
                return [];
            }
        }

        PHP;

        File::put($this->targetPath, $already);

        $context = $this->makeContext();
        (new UpgradeRebuildDatabaseCommandStep())->run($context);

        $this->assertSame(0, $context->filesScaffolded);
        $this->assertSame($already, File::get($this->targetPath));
    }

    public function test_records_conflict_when_base_steps_missing(): void
    {
        // A custom step array that doesn't start with the canonical base keys.
        $nonCanonical = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Console\Commands;

        use Illuminate\Console\Command;
        use Northwestern\SysDev\Chassis\Console\Concerns\RunsSteps;

        class RebuildDatabaseCommand extends Command
        {
            use RunsSteps;

            protected $signature = 'db:rebuild';

            public function handle(): int
            {
                $steps = [
                    'Something custom' => fn () => null,
                ];

                return self::SUCCESS;
            }
        }

        PHP;

        File::put($this->targetPath, $nonCanonical);

        $context = $this->makeContext();
        (new UpgradeRebuildDatabaseCommandStep())->run($context);

        $this->assertSame(0, $context->filesScaffolded);
        $this->assertCount(1, $context->conflicts);
        $this->assertStringContainsString('canonical chassis base steps', $context->conflicts[0]);
    }

    public function test_is_idempotent(): void
    {
        File::put($this->targetPath, $this->canonicalPreChassisFile());

        $context = $this->makeContext();
        (new UpgradeRebuildDatabaseCommandStep())->run($context);
        $afterFirst = File::get($this->targetPath);

        $context2 = $this->makeContext();
        (new UpgradeRebuildDatabaseCommandStep())->run($context2);
        $afterSecond = File::get($this->targetPath);

        $this->assertSame($afterFirst, $afterSecond, 're-running should be a no-op');
        $this->assertSame(0, $context2->filesScaffolded, 'second run should not count a change');
    }

    public function test_dry_run_does_not_modify_file(): void
    {
        $original = $this->canonicalPreChassisFile();
        File::put($this->targetPath, $original);

        $context = new MigrationContext(isDryRun: true, command: $this->makeSilentCommand());
        (new UpgradeRebuildDatabaseCommandStep())->run($context);

        $this->assertSame($original, File::get($this->targetPath), 'dry run should not write');
        $this->assertSame(1, $context->filesScaffolded, 'dry run still reports the change');
    }

    private function canonicalPreChassisFile(): string
    {
        return <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Console\Commands;

        use Illuminate\Console\Command;
        use Illuminate\Support\Facades\App;
        use Illuminate\Support\Facades\Queue;
        use Northwestern\SysDev\Chassis\Console\Concerns\RunsSteps;
        use Throwable;

        /**
         * Rebuilds the database from scratch with fresh migrations and seeders.
         */
        class RebuildDatabaseCommand extends Command
        {
            use RunsSteps;

            protected $signature = 'db:rebuild';

            protected $description = 'Rebuild the database and regenerate IDE helper files';

            public function handle(): int
            {
                if (App::isProduction()) {
                    $this->components->error('This command cannot be run in production.');

                    return self::FAILURE;
                }

                $this->newLine();
                $this->components->info('Rebuilding Database');

                $steps = [
                    'Clearing cache' => $this->clearCache(...),
                    'Clearing queue' => fn () => $this->callSilently('queue:clear', ['--force' => true]),
                    'Clearing schedule cache' => fn () => $this->callSilently('schedule:clear-cache'),
                    'Running migrations' => fn () => $this->callSilently('migrate:fresh', ['--force' => true]),
                    'Seeding database' => fn () => $this->callSilently('db:seed', ['--force' => true]),
                    'Seeding demo data' => fn () => $this->callSilently('db:seed', ['--class' => 'DemoSeeder', '--force' => true]),
                    'Generating IDE helpers' => fn () => $this->callSilently('ide-helper:models', ['-N' => true]),
                ];

                foreach ($steps as $name => $callback) {
                    if (! $this->runStep($name, $callback)) {
                        $this->displaySummary();

                        return self::FAILURE;
                    }
                }

                $this->displaySummary();
                $this->displayPostBuildInfo();

                return $this->allPassed() ? self::SUCCESS : self::FAILURE;
            }

            protected function successMessage(): string
            {
                return 'Database rebuild complete';
            }

            protected function clearCache(): void
            {
                try {
                    $this->callSilently('cache:clear');
                } catch (Throwable) {
                    // Ignore - database cache table may not exist yet
                }
            }

            protected function displayPostBuildInfo(): void
            {
                if (! $this->allPassed()) {
                    return;
                }

                $queueSize = Queue::size();

                if ($queueSize > 0) {
                    $this->components->warn("There are {$queueSize} jobs pending in the queue.");
                    $this->line('  <fg=gray>→</> Run <comment>php artisan queue:work</comment> to process them');
                    $this->newLine();
                }
            }
        }
        PHP;
    }

    private function makeContext(): MigrationContext
    {
        return new MigrationContext(isDryRun: false, command: $this->makeSilentCommand());
    }

    private function makeSilentCommand(): Command
    {
        return new class extends Command
        {
            protected $signature = 'test:silent';

            public function line($string, $style = null, $verbosity = null): void
            {
                // Swallow output during tests.
            }
        };
    }
}
