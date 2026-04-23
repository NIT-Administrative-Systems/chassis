<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Services;

use Illuminate\Support\Facades\File;
use Northwestern\SysDev\Chassis\Attributes\ValidatesConfig;
use Northwestern\SysDev\Chassis\Services\ConfigValidatorResolver;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use Northwestern\SysDev\Chassis\ValueObjects\ResolvedValidator;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ConfigValidatorResolver::class)]
#[CoversClass(ValidatesConfig::class)]
class ConfigValidatorResolverTest extends TestCase
{
    private function fixturesPath(string $subPath = ''): string
    {
        return __DIR__ . '/../../Fixtures' . ($subPath !== '' && $subPath !== '0' ? '/' . $subPath : '');
    }

    public function test_discovers_validators_in_fixture_directory(): void
    {
        $resolver = new ConfigValidatorResolver();
        $resolved = $resolver->discover($this->fixturesPath('Validators'));

        $this->assertNotEmpty($resolved);
        $this->assertContainsOnlyInstancesOf(ResolvedValidator::class, $resolved);

        $descriptions = array_map(fn (ResolvedValidator $r) => $r->description, $resolved);

        $this->assertSame([
            'Cache Store',
            'Database Connection',
        ], $descriptions);
    }

    public function test_resolved_validators_have_descriptions(): void
    {
        $resolver = new ConfigValidatorResolver();
        $resolved = $resolver->discover($this->fixturesPath('Validators'));

        foreach ($resolved as $item) {
            $this->assertNotEmpty($item->description);
        }
    }

    public function test_returns_empty_array_for_nonexistent_path(): void
    {
        $resolver = new ConfigValidatorResolver();
        $validators = $resolver->discover('/nonexistent/path');

        $this->assertSame([], $validators);
    }

    public function test_returns_empty_array_for_directory_with_no_validators(): void
    {
        $tmpDir = sys_get_temp_dir() . '/chassis-validator-test-' . uniqid();
        mkdir($tmpDir, 0755, true);

        file_put_contents($tmpDir . '/SomeClass.php', "<?php\nnamespace ChassisTestTmp;\nclass SomeClass {}\n");

        try {
            $resolver = new ConfigValidatorResolver();
            $validators = $resolver->discover($tmpDir);

            $this->assertSame([], $validators);
        } finally {
            File::deleteDirectory($tmpDir);
        }
    }

    public function test_supports_array_of_paths(): void
    {
        $resolver = new ConfigValidatorResolver();
        $resolved = $resolver->discover([$this->fixturesPath('Validators')]);

        $this->assertNotEmpty($resolved);
    }

    public function test_deduplicates_validators_from_overlapping_paths(): void
    {
        $resolver = new ConfigValidatorResolver();
        $resolved = $resolver->discover([
            $this->fixturesPath('Validators'),
            $this->fixturesPath('Validators'),
        ]);

        $classes = array_map(fn (ResolvedValidator $r) => $r->validator::class, $resolved);

        $this->assertSame($classes, array_unique($classes));
    }

    public function test_ignores_php_files_without_namespace(): void
    {
        $tmpDir = sys_get_temp_dir() . '/chassis-validator-test-' . uniqid();
        mkdir($tmpDir, 0755, true);

        file_put_contents($tmpDir . '/NoNamespace.php', "<?php\nclass NoNamespace {}\n");

        try {
            $resolver = new ConfigValidatorResolver();
            $validators = $resolver->discover($tmpDir);

            $this->assertSame([], $validators);
        } finally {
            File::deleteDirectory($tmpDir);
        }
    }

    public function test_ignores_non_php_files(): void
    {
        $tmpDir = sys_get_temp_dir() . '/chassis-validator-test-' . uniqid();
        mkdir($tmpDir, 0755, true);

        file_put_contents($tmpDir . '/readme.txt', 'not a php file');

        try {
            $resolver = new ConfigValidatorResolver();
            $validators = $resolver->discover($tmpDir);

            $this->assertSame([], $validators);
        } finally {
            File::deleteDirectory($tmpDir);
        }
    }

    public function test_ignores_config_validator_without_attribute(): void
    {
        $tmpDir = sys_get_temp_dir() . '/chassis-validator-test-' . uniqid();
        mkdir($tmpDir, 0755, true);

        file_put_contents(
            $tmpDir . '/UnattributedValidator.php',
            "<?php\nnamespace Northwestern\\SysDev\\Chassis\\Tests\\Fixtures\\Validators;\nclass UnattributedValidator {}\n"
        );

        try {
            $resolver = new ConfigValidatorResolver();
            $validators = $resolver->discover($tmpDir);

            $this->assertSame([], $validators);
        } finally {
            File::deleteDirectory($tmpDir);
        }
    }

    public function test_ignores_abstract_validator_even_when_attributed(): void
    {
        // AbstractAttributedValidator is a real fixture with the attribute but
        // marked abstract, so the resolver must skip it (exercises the
        // `isAbstract() || ! implementsInterface(...)` guard).
        $resolver = new ConfigValidatorResolver();
        $resolved = $resolver->discover($this->fixturesPath('Validators'));

        $classes = array_map(fn (ResolvedValidator $r) => $r->validator::class, $resolved);

        $this->assertNotContains(
            \Northwestern\SysDev\Chassis\Tests\Fixtures\Validators\AbstractAttributedValidator::class,
            $classes,
        );
    }

    public function test_discovers_validators_from_glob_pattern(): void
    {
        // Create a nested directory structure that the glob will expand into.
        $baseDir = sys_get_temp_dir() . '/chassis-validator-glob-' . uniqid();
        $subDir1 = $baseDir . '/ModuleA/Validators';
        $subDir2 = $baseDir . '/ModuleB/Validators';

        mkdir($subDir1, 0755, true);
        mkdir($subDir2, 0755, true);

        // Copy the real Validators fixtures into one of the subdirs.
        $source = $this->fixturesPath('Validators');
        foreach (glob($source . '/*.php') ?: [] as $file) {
            $dest = $subDir1 . '/' . basename($file);
            copy($file, $dest);
        }

        try {
            $resolver = new ConfigValidatorResolver();
            $resolved = $resolver->discover($baseDir . '/*/Validators');

            // Glob should have expanded to subDir1 and subDir2.
            $this->assertNotEmpty($resolved);
        } finally {
            File::deleteDirectory($baseDir);
        }
    }

    public function test_throws_when_resolved_class_does_not_implement_config_validator(): void
    {
        // Override the container binding for a real validator class so that
        // resolve() returns an object that does not implement the contract.
        $validatorClass = \Northwestern\SysDev\Chassis\Tests\Fixtures\Validators\TestCacheValidator::class;

        $this->app->bind($validatorClass, fn () => new \stdClass());

        try {
            $resolver = new ConfigValidatorResolver();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('does not implement ConfigValidator');

            $resolver->discover($this->fixturesPath('Validators'));
        } finally {
            // @phpstan-ignore-next-line — offset unset is fine on the container.
            unset($this->app[$validatorClass]);
        }
    }
}
