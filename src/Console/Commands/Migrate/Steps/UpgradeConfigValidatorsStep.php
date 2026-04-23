<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps;

use Illuminate\Support\Facades\File;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Concerns\InteractsWithAst;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationContext;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Upgrade old-style config validators that use a name() method to the
 * new #[ValidatesConfig(description: '...')] attribute-based approach.
 */
class UpgradeConfigValidatorsStep extends AbstractMigrationStep
{
    use InteractsWithAst;

    /** @var non-empty-list<string> */
    private const array DEFAULT_VALIDATOR_DIRECTORIES = [
        'app/Domains/Core/Services/ConfigValidation',
    ];

    public function label(): string
    {
        return 'Upgrading config validators...';
    }

    public function run(MigrationContext $context): void
    {
        /** @var list<string> $directories */
        $directories = array_values(array_filter([
            ...array_map(static fn (string $relativePath): string => base_path($relativePath), self::DEFAULT_VALIDATOR_DIRECTORIES),
            ...glob(base_path('app/Domains/*/Services/ConfigValidation')) ?: [],
        ], 'is_dir'));

        if ($directories === []) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($directories)->name('*.php');

        if (! $finder->hasResults()) {
            return;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $printer = new Standard();

        foreach ($finder as $file) {
            $this->upgradeValidatorFile($file, $parser, $printer, $context);
        }
    }

    /**
     * Rewrite one validator class from the legacy `name()` contract to the
     * attribute-driven contract expected by the chassis package.
     */
    private function upgradeValidatorFile(SplFileInfo $file, Parser $parser, Standard $printer, MigrationContext $context): void
    {
        $code = File::get($file->getRealPath());
        $oldStmts = $parser->parse($code);

        if ($oldStmts === null) {
            return;
        }

        $classNode = $this->findFirstClassStatement($oldStmts);
        if (! $classNode instanceof Class_) {
            return;
        }

        if (! $this->implementsConfigValidator($classNode)) {
            return;
        }

        if ($this->alreadyHasValidatorAttribute($classNode)) {
            return;
        }

        $nameMethod = $this->findClassMethodByName($classNode, 'name');

        // Clone AST for format-preserving printing
        $newStmts = $this->cloneStatementTree($oldStmts);

        // Find the class node again in the cloned AST
        $clonedClass = $this->findFirstClassStatement($newStmts);
        if (! $clonedClass instanceof Class_) {
            return;
        }

        $changes = [];

        // Determine the description for the #[ValidatesConfig] attribute
        $description = null;

        if ($nameMethod instanceof ClassMethod) {
            // Extract from name() return value
            $description = $this->extractNameMethodDescription($nameMethod);

            // Remove the name() method
            $clonedClass->stmts = array_values(array_filter(
                $clonedClass->stmts,
                fn (Node $stmt): bool => ! ($stmt instanceof ClassMethod && $stmt->name->toString() === 'name'),
            ));
            $changes[] = 'removed name()';
        }

        // Fall back to deriving description from class name: DatabaseValidator → "Database"
        if ($description === null && $classNode->name instanceof Identifier) {
            $description = $this->deriveDescriptionFromClassName($classNode->name->toString());
        }
        $description ??= 'Configuration';

        // Add #[ValidatesConfig(description: '...')] attribute
        $validatesConfigAttr = new Attribute(
            new Name('ValidatesConfig'),
            [new Arg(new String_($description), name: new Identifier('description'))],
        );
        array_unshift($clonedClass->attrGroups, new AttributeGroup([$validatesConfigAttr]));
        $this->ensureClassImport($newStmts, 'Northwestern\\SysDev\\Chassis\\Attributes\\ValidatesConfig');
        $changes[] = 'added #[ValidatesConfig]';

        // Add missing interface methods with sensible defaults
        $missingMethods = [
            'shouldRun' => new ClassMethod('shouldRun', [
                'flags' => Modifiers::PUBLIC,
                'returnType' => new Identifier('bool'),
                'stmts' => [new Return_(new Node\Expr\ConstFetch(new Name('true')))],
            ]),
            'hints' => new ClassMethod('hints', [
                'flags' => Modifiers::PUBLIC,
                'returnType' => new Identifier('array'),
                'stmts' => [new Return_(new Node\Expr\Array_())],
            ]),
        ];

        foreach ($missingMethods as $methodName => $methodNode) {
            $exists = false;
            foreach ($clonedClass->stmts as $stmt) {
                if ($stmt instanceof ClassMethod && $stmt->name->toString() === $methodName) {
                    $exists = true;
                    break;
                }
            }

            if (! $exists) {
                $clonedClass->stmts[] = $methodNode;
                $changes[] = "added {$methodName}()";
            }
        }

        $newCode = $this->printWithOriginalFormatting($printer, $parser, $newStmts, $oldStmts);

        if (! $context->isDryRun) {
            File::put($file->getRealPath(), $newCode);
        }

        $this->markFileModified($context);
        $relativePath = $this->toRelativePath($file->getRealPath());
        $this->success($context, $relativePath . ' (' . implode(', ', $changes) . ')');
    }

    /**
     * Derive a human-readable description from a class name.
     *
     * DatabaseValidator → "Database"
     * EnvironmentVariablesValidator → "Environment Variables"
     * SSOValidator → "SSO"
     */
    private function deriveDescriptionFromClassName(string $className): string
    {
        $name = str_replace('Validator', '', $className);

        // Insert spaces before uppercase letters: "EnvironmentVariables" → "Environment Variables"
        $spaced = (string) preg_replace('/([a-z])([A-Z])/', '$1 $2', $name);

        return trim($spaced) !== '' ? trim($spaced) : $className;
    }

    /**
     * Treat both legacy and namespaced `ConfigValidator` references as eligible
     * because apps may still be mid-migration when this command runs.
     */
    private function implementsConfigValidator(Class_ $classNode): bool
    {
        foreach ($classNode->implements as $implementedInterface) {
            $interfaceName = $implementedInterface->toString();

            if ($interfaceName === 'ConfigValidator' || str_ends_with($interfaceName, '\\ConfigValidator')) {
                return true;
            }
        }

        return false;
    }

    private function alreadyHasValidatorAttribute(Class_ $classNode): bool
    {
        foreach ($classNode->attrGroups as $attributeGroup) {
            foreach ($attributeGroup->attrs as $attribute) {
                $attributeName = $attribute->name->toString();

                if ($attributeName === 'ValidatesConfig'
                    || str_ends_with($attributeName, '\\ValidatesConfig')
                    || $attributeName === 'StarterValidator'
                    || str_ends_with($attributeName, '\\StarterValidator')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function findClassMethodByName(Class_ $classNode, string $methodName): ?ClassMethod
    {
        foreach ($classNode->stmts as $classStatement) {
            if ($classStatement instanceof ClassMethod && $classStatement->name->toString() === $methodName) {
                return $classStatement;
            }
        }

        return null;
    }

    /**
     * Extract the string return value from the legacy `name()` method.
     */
    private function extractNameMethodDescription(ClassMethod $method): ?string
    {
        if ($method->stmts === null) {
            return null;
        }

        foreach ($method->stmts as $stmt) {
            if ($stmt instanceof Return_ && $stmt->expr instanceof String_) {
                return $stmt->expr->value;
            }
        }

        return null;
    }
}
