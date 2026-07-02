<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Unit\Database;

use Northwestern\SysDev\Chassis\Database\SchemaChecksumManager;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SchemaChecksumManager::class)]
class SchemaChecksumManagerTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = sys_get_temp_dir() . '/chassis-schema-' . uniqid('', true);
        $this->scaffoldProject();
        $this->app->setBasePath($this->projectRoot);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->projectRoot);

        parent::tearDown();
    }

    public function test_collect_schema_files_finds_application_migrations_and_seeders(): void
    {
        $files = (new SchemaChecksumManager())->collectSchemaFiles();

        $migrationNames = array_map('basename', $files->migrations);
        $seederNames = array_map('basename', $files->seeders);

        $this->assertContains('2024_01_01_000000_create_users_table.php', $migrationNames);
        $this->assertContains('DatabaseSeeder.php', $seederNames);
        // Seeders nested in first-party code (e.g. modular packages) are still discovered.
        $this->assertContains('ModuleSeeder.php', $seederNames);
    }

    public function test_collect_schema_files_excludes_heavy_dependency_directories(): void
    {
        $files = (new SchemaChecksumManager())->collectSchemaFiles();

        $seederNames = array_map('basename', $files->seeders);

        // These live under directories Finder must not descend into; walking them
        // is what made schema validation slow on Windows.
        $this->assertNotContains('VendorSeeder.php', $seederNames);
        $this->assertNotContains('NodeModulesSeeder.php', $seederNames);
        $this->assertNotContains('StorageSeeder.php', $seederNames);
    }

    public function test_calculate_checksum_reuses_a_provided_collection(): void
    {
        $manager = new SchemaChecksumManager();
        $files = $manager->collectSchemaFiles();

        // Passing a pre-collected set must yield the same checksum as scanning again,
        // proving the reuse path avoids a redundant filesystem walk without drift.
        $this->assertSame(
            $manager->calculateCurrentCodebaseChecksum(),
            $manager->calculateCurrentCodebaseChecksum($files),
        );
    }

    private function scaffoldProject(): void
    {
        $files = [
            'database/migrations/2024_01_01_000000_create_users_table.php' => "<?php // migration\n",
            'database/seeders/DatabaseSeeder.php' => "<?php // app seeder\n",
            'Modules/Billing/database/seeders/ModuleSeeder.php' => "<?php // module seeder\n",
            'vendor/acme/pkg/database/seeders/VendorSeeder.php' => "<?php // vendor seeder\n",
            'node_modules/some-pkg/seeders/NodeModulesSeeder.php' => "<?php // node seeder\n",
            'storage/framework/seeders/StorageSeeder.php' => "<?php // storage seeder\n",
        ];

        foreach ($files as $relativePath => $contents) {
            $absolutePath = $this->projectRoot . '/' . $relativePath;
            $directory = dirname($absolutePath);

            if (! is_dir($directory)) {
                mkdir($directory, 0o777, recursive: true);
            }

            file_put_contents($absolutePath, $contents);
        }
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
