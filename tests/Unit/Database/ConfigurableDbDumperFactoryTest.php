<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Unit\Database;

use Northwestern\SysDev\Chassis\Database\ConfigurableDbDumperFactory;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(ConfigurableDbDumperFactory::class)]
class ConfigurableDbDumperFactoryTest extends TestCase
{
    public function test_it_uses_configured_postgres_binary_directory(): void
    {
        config()->set('db-snapshots.pg_bin_directory', '/usr/bin');

        $this->assertSame('/usr/bin', ConfigurableDbDumperFactory::findPostgresDirectory());
    }

    public function test_it_allows_path_resolution_when_no_directory_is_configured(): void
    {
        config()->set('db-snapshots.pg_bin_directory');

        $directory = ConfigurableDbDumperFactory::findPostgresDirectory();

        $this->assertTrue($directory === null || is_string($directory));
    }

    public function test_home_directory_error_references_the_config_key(): void
    {
        $original = getenv('USERPROFILE');
        putenv('USERPROFILE');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('db-snapshots.pg_bin_directory');

            (new ReflectionMethod(ConfigurableDbDumperFactory::class, 'windowsHomeDir'))->invoke(null);
        } finally {
            if ($original !== false) {
                putenv("USERPROFILE={$original}");
            }
        }
    }
}
