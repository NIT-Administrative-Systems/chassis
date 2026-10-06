<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Cypress occasionally fails to swap the `.env` files back after running the test suite. This command is useful
 * for local testing to easily rename the `.env` and `.env.backup` files back to their original filenames.
 *
 * @deprecated 1.4.0 Consider moving browser tests to Pest's browser plugin, which runs them inside the
 *             test process without swapping `.env` files. The Northwestern Laravel Starter shows how:
 *             https://laravel-starter.entapp.northwestern.edu/guides/testing/
 */
class RestoreLocalEnvironmentFilesCommand extends Command
{
    protected $signature = 'restore-env-files';

    protected $description = 'Restore the local environment file from a backup created by Cypress.';

    public function handle(): int
    {
        $this->components->warn(
            "restore-env-files is deprecated. Pest's browser plugin runs browser tests without swapping .env files; "
            . 'see how the Northwestern Laravel Starter uses it: https://laravel-starter.entapp.northwestern.edu/guides/testing/',
        );

        $envPath = base_path('.env');
        $envCypressPath = base_path('.env.cypress');
        $envBackupPath = base_path('.env.backup');

        if (File::missing($envBackupPath)) {
            $this->components->error('The <options=bold;fg=green>.env.backup</> file does not exist.');

            return self::FAILURE;
        }

        if (File::exists($envPath)) {
            File::move($envPath, $envCypressPath);
        }
        File::move($envBackupPath, $envPath);

        $this->components->info('Environment files have been restored successfully.');

        return self::SUCCESS;
    }
}
