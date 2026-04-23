<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Unit\Seeding\ValueObjects;

use Northwestern\SysDev\Chassis\Seeding\ValueObjects\SeederInfo;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SeederInfo::class)]
class SeederInfoTest extends TestCase
{
    public function test_get_short_name_extracts_class_basename(): void
    {
        $info = new SeederInfo(className: 'App\\Domains\\Auth\\Seeders\\RoleSeeder');

        $this->assertSame('RoleSeeder', $info->getShortName());
    }

    public function test_has_dependencies_returns_false_when_empty(): void
    {
        $info = new SeederInfo(className: 'App\\Seeders\\PermissionSeeder');

        $this->assertFalse($info->hasDependencies());
    }

    public function test_has_dependencies_returns_true_when_present(): void
    {
        $info = new SeederInfo(
            className: 'App\\Seeders\\RoleSeeder',
            dependsOn: ['App\\Seeders\\PermissionSeeder'],
        );

        $this->assertTrue($info->hasDependencies());
    }

    public function test_get_dependency_short_names(): void
    {
        $info = new SeederInfo(
            className: 'App\\Seeders\\RoleSeeder',
            dependsOn: ['App\\Seeders\\PermissionSeeder', 'App\\Seeders\\RoleSeeder'],
        );

        $this->assertSame(['PermissionSeeder', 'RoleSeeder'], $info->getDependencyShortNames());
    }

    public function test_get_dependency_short_names_returns_empty_when_no_dependencies(): void
    {
        $info = new SeederInfo(className: 'App\\Seeders\\PermissionSeeder');

        $this->assertSame([], $info->getDependencyShortNames());
    }

    public function test_defaults_to_empty_depends_on(): void
    {
        $info = new SeederInfo(className: 'App\\Seeders\\PermissionSeeder');

        $this->assertSame([], $info->dependsOn);
    }
}
