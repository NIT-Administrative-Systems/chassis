<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Seeding;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Northwestern\SysDev\Chassis\Attributes\AutoSeed;
use Northwestern\SysDev\Chassis\Contracts\IdempotentSeederInterface;
use Northwestern\SysDev\Chassis\Seeding\ValueObjects\SeederInfo;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;

/**
 * Discovers and resolves idempotent seeders in dependency order.
 *
 * Automatically scans directories for seeders decorated with #[AutoSeed], validates
 * their dependencies, and returns them in topologically sorted execution order using
 * depth-first search. Detects circular dependencies and missing references.
 *
 * @phpstan-type SeederClass class-string<IdempotentSeederInterface>
 */
class IdempotentSeederResolver
{
    /**
     * @var array<SeederClass, SeederInfo>
     */
    private array $seeders = [];

    /**
     * @var array<SeederClass>
     */
    private array $resolved = [];

    /**
     * @var array<SeederClass>
     */
    private array $resolving = [];

    /**
     * Discover and resolve seeders from the given path(s).
     *
     * Supports glob patterns like 'app/Domains/*\/Seeders' to scan multiple directories.
     *
     * @param  string|array<string>|null  $paths  Directory path(s) or glob pattern(s) to scan
     * @return list<SeederInfo> Seeders in dependency-resolved order
     */
    public function discover(string|array|null $paths = null): array
    {
        $paths ??= [app_path('Domains/**/Seeders')];
        $paths = is_array($paths) ? $paths : [$paths];

        $this->seeders = [];
        $this->resolved = [];
        $this->resolving = [];

        $discoveredPaths = $this->expandGlobPatterns($paths);

        if ($discoveredPaths->isEmpty()) {
            return [];
        }

        $seederClasses = $discoveredPaths
            ->flatMap(fn (string $path): Collection => $this->scanDirectory($path))
            ->unique()
            ->values();

        $this->buildSeederRegistry($seederClasses);

        return $this->topologicalSort();
    }

    /**
     * Validate all discovered seeders for circular dependencies and missing references.
     *
     * @return array<string> Array of validation error messages (empty if valid)
     */
    public function validate(): array
    {
        $errors = [];

        try {
            $this->topologicalSort();
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }

        return $errors;
    }

    /**
     * Expand glob patterns and filter to existing directories.
     *
     * @param  array<string>  $patterns
     * @return Collection<int, string>
     */
    private function expandGlobPatterns(array $patterns): Collection
    {
        return collect($patterns)
            ->flatMap(function (string $pattern): array {
                if (str_contains($pattern, '*')) {
                    return glob($pattern, GLOB_ONLYDIR) ?: [];
                }

                return [$pattern];
            })
            ->filter(fn (string $path): bool => is_dir($path))
            ->unique()
            ->values();
    }

    /**
     * Scan a directory for seeder class files.
     *
     * @return Collection<int, SeederClass>
     */
    private function scanDirectory(string $path): Collection
    {
        /** @var Collection<int, SeederClass> */
        return collect(File::allFiles($path))
            ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
            ->map(fn (SplFileInfo $file): ?string => $this->extractFullyQualifiedClassName($file))
            ->filter(function (?string $class): bool {
                if ($class === null || ! class_exists($class)) {
                    return false;
                }

                /** @var class-string $class */
                return $this->isValidSeederClass($class);
            })
            ->values();
    }

    /**
     * Extract the fully qualified class name from a PHP file.
     */
    private function extractFullyQualifiedClassName(SplFileInfo $file): ?string
    {
        $namespace = $this->parseNamespaceFromFile($file->getRealPath());

        if ($namespace === null) {
            return null;
        }

        $className = pathinfo($file->getFilename(), PATHINFO_FILENAME);

        return sprintf('%s\\%s', $namespace, $className);
    }

    /**
     * Parse the namespace declaration from a PHP file.
     */
    private function parseNamespaceFromFile(string $filePath): ?string
    {
        $handle = @fopen($filePath, 'rb');

        if ($handle === false) {
            return null;
        }

        $namespace = null;

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if (str_starts_with($line, 'namespace ')) {
                $namespace = trim(substr($line, 10), '; ');
                break;
            }

            // Stop parsing after class declaration to avoid reading an entire file
            if (str_starts_with($line, 'class ') || str_starts_with($line, 'final class ')) {
                break;
            }
        }

        fclose($handle);

        return $namespace;
    }

    /**
     * Check if a class is a valid, instantiable seeder.
     *
     * @param  class-string  $className
     */
    private function isValidSeederClass(string $className): bool
    {
        // @codeCoverageIgnoreStart
        // Defensive guard: callers (scanDirectory) already verify class_exists
        // before reaching this method, so this branch is unreachable in practice.
        if (! class_exists($className)) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        $reflection = new ReflectionClass($className);

        return $reflection->isSubclassOf(IdempotentSeederInterface::class)
            && ! $reflection->isAbstract();
    }

    /**
     * Build the internal registry of seeders with their metadata.
     *
     * @param  Collection<int, SeederClass>  $seederClasses
     */
    private function buildSeederRegistry(Collection $seederClasses): void
    {
        foreach ($seederClasses as $className) {
            $seederInfo = $this->extractSeederMetadata($className);

            if ($seederInfo instanceof SeederInfo) {
                $this->seeders[$className] = $seederInfo;
            }
        }
    }

    /**
     * Extract seeder metadata from the AutoSeed attribute.
     *
     * @param  SeederClass  $className
     */
    private function extractSeederMetadata(string $className): ?SeederInfo
    {
        $reflection = new ReflectionClass($className);

        $attributes = $reflection->getAttributes(AutoSeed::class);

        if (blank($attributes)) {
            return null;
        }

        /** @var AutoSeed $attribute */
        $attribute = $attributes[0]->newInstance();

        return new SeederInfo(
            className: $className,
            dependsOn: $attribute->dependsOn,
        );
    }

    /**
     * Sorts seeders using topological sorting to ensure dependencies run before dependents.
     *
     * @return list<SeederInfo> Seeders ordered such that all dependencies run first
     *
     * @throws RuntimeException If circular dependencies are detected
     */
    private function topologicalSort(): array
    {
        /** @var array<SeederInfo> $ordered */
        $ordered = [];

        foreach (array_keys($this->seeders) as $seederClass) {
            $this->visitNode($seederClass, $ordered);
        }

        return array_values($ordered);
    }

    /**
     * Recursively visits a seeder node using depth-first search for topological sorting.
     *
     * @param  SeederClass  $seederClass  The seeder to visit
     * @param  array<SeederInfo>  $ordered  The output array being built (passed by reference)
     *
     * @throws RuntimeException If circular dependency or missing seeder is detected
     */
    private function visitNode(string $seederClass, array &$ordered): void
    {
        if (in_array($seederClass, $this->resolved, true)) {
            return;
        }

        if (in_array($seederClass, $this->resolving, true)) {
            throw new RuntimeException(sprintf(
                'Circular dependency detected: %s',
                implode(' → ', [...$this->resolving, $seederClass])
            ));
        }

        if (! isset($this->seeders[$seederClass])) {
            throw new RuntimeException(sprintf(
                "Seeder '%s' is declared as a dependency but was not discovered or is missing the #[AutoSeed] attribute",
                $seederClass
            ));
        }

        $this->resolving[] = $seederClass;
        $seederInfo = $this->seeders[$seederClass];

        foreach ($seederInfo->dependsOn as $dependency) {
            $this->visitNode($dependency, $ordered);
        }

        array_pop($this->resolving);
        $this->resolved[] = $seederClass;
        $ordered[] = $seederInfo;
    }
}
