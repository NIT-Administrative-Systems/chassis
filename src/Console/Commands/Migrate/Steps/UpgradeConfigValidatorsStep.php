<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps;

use Illuminate\Support\Facades\File;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Concerns\TracksChanges;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Contracts\MigrationStep;
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
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Upgrade old-style config validators that use a name() method to the
 * new #[ValidatesConfig(description: '...')] attribute-based approach.
 */
class UpgradeConfigValidatorsStep implements MigrationStep
{
    use TracksChanges;

    public function label(): string
    {
        return 'Upgrading config validators...';
    }

    public function run(MigrationContext $context): void
    {
        $directories = array_filter([
            base_path('app/Domains/Core/Services/ConfigValidation'),
            ...glob(base_path('app/Domains/*/Services/ConfigValidation')) ?: [],
        ], 'is_dir');

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
            $this->upgradeConfigValidatorFile($file, $parser, $printer, $context);
        }
    }

    private function upgradeConfigValidatorFile(SplFileInfo $file, Parser $parser, Standard $printer, MigrationContext $context): void
    {
        $code = File::get($file->getRealPath());
        $oldStmts = $parser->parse($code);

        if ($oldStmts === null) {
            return;
        }

        // Find the class node
        $classNode = $this->findClassNode($oldStmts);
        if (! $classNode instanceof Class_) {
            return;
        }

        // Check if the class implements ConfigValidator (old or new namespace)
        $implementsConfigValidator = false;
        foreach ($classNode->implements as $interface) {
            $name = $interface->toString();
            if ($name === 'ConfigValidator'
                || str_ends_with($name, '\\ConfigValidator')) {
                $implementsConfigValidator = true;
                break;
            }
        }

        if (! $implementsConfigValidator) {
            return;
        }

        // Check if the class already has a #[ValidatesConfig] or #[StarterValidator] attribute
        foreach ($classNode->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $attrName = $attr->name->toString();
                if ($attrName === 'ValidatesConfig'
                    || str_ends_with($attrName, '\\ValidatesConfig')
                    || $attrName === 'StarterValidator'
                    || str_ends_with($attrName, '\\StarterValidator')) {
                    return;
                }
            }
        }

        // Check if the class has a name() method
        $nameMethod = null;
        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof ClassMethod && $stmt->name->toString() === 'name') {
                $nameMethod = $stmt;
                break;
            }
        }

        // Clone AST for format-preserving printing
        $cloneTraverser = new NodeTraverser();
        $cloneTraverser->addVisitor(new CloningVisitor());
        /** @var list<Node\Stmt> $newStmts */
        $newStmts = $cloneTraverser->traverse($oldStmts);

        // Find the class node again in the cloned AST
        $clonedClass = $this->findClassNode($newStmts);
        if (! $clonedClass instanceof Class_) {
            return;
        }

        $changes = [];

        // Determine the description for the #[ValidatesConfig] attribute
        $description = null;

        if ($nameMethod instanceof ClassMethod) {
            // Extract from name() return value
            $description = $this->extractNameReturnValue($nameMethod);

            // Remove the name() method
            $clonedClass->stmts = array_values(array_filter(
                $clonedClass->stmts,
                fn (Node $stmt): bool => ! ($stmt instanceof ClassMethod && $stmt->name->toString() === 'name'),
            ));
            $changes[] = 'removed name()';
        }

        // Fall back to deriving description from class name: DatabaseValidator → "Database"
        if ($description === null && $classNode->name instanceof Identifier) {
            $description = $this->descriptionFromClassName($classNode->name->toString());
        }
        $description ??= 'Configuration';

        // Add #[ValidatesConfig(description: '...')] attribute
        $validatesConfigAttr = new Attribute(
            new Name('ValidatesConfig'),
            [new Arg(new String_($description), name: new Identifier('description'))],
        );
        array_unshift($clonedClass->attrGroups, new AttributeGroup([$validatesConfigAttr]));
        $this->ensureUseStatement($newStmts, 'Northwestern\\SysDev\\Chassis\\Attributes\\ValidatesConfig');
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

        $newCode = $printer->printFormatPreserving($newStmts, $oldStmts, $parser->getTokens());

        if (! $context->isDryRun) {
            File::put($file->getRealPath(), $newCode);
        }

        $this->incrementCounter($context, 'filesScaffolded');
        $relativePath = $this->relativePath($file->getRealPath());
        $context->command->line('  <fg=green>✓</> ' . $relativePath . ' (' . implode(', ', $changes) . ')');
    }

    /**
     * Find the first Class_ node in a statement list, including inside a Namespace_ node.
     *
     * @param  array<Node\Stmt>  $stmts
     */
    private function findClassNode(array $stmts): ?Class_
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Class_) {
                return $stmt;
            }

            if ($stmt instanceof Namespace_) {
                foreach ($stmt->stmts as $nsStmt) {
                    if ($nsStmt instanceof Class_) {
                        return $nsStmt;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extract the string return value from a name() method.
     */
    /**
     * Derive a human-readable description from a class name.
     *
     * DatabaseValidator → "Database"
     * EnvironmentVariablesValidator → "Environment Variables"
     * SSOValidator → "SSO"
     */
    private function descriptionFromClassName(string $className): string
    {
        $name = str_replace('Validator', '', $className);

        // Insert spaces before uppercase letters: "EnvironmentVariables" → "Environment Variables"
        $spaced = (string) preg_replace('/([a-z])([A-Z])/', '$1 $2', $name);

        return trim($spaced) !== '' ? trim($spaced) : $className;
    }

    private function extractNameReturnValue(ClassMethod $method): ?string
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

    /**
     * Ensure a use statement exists in the file's top-level statements.
     *
     * @param  array<Node\Stmt>  $stmts
     */
    private function ensureUseStatement(array &$stmts, string $fqcn): void
    {
        // Check inside Namespace_ node or top-level
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_) {
                $this->ensureUseStatementInList($stmt->stmts, $fqcn);

                return;
            }
        }

        $this->ensureUseStatementInList($stmts, $fqcn);
    }

    /**
     * @param  array<Node\Stmt>  $stmts
     */
    private function ensureUseStatementInList(array &$stmts, string $fqcn): void
    {
        // Check if already imported
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Use_) {
                foreach ($stmt->uses as $use) {
                    if ($use->name->toString() === $fqcn) {
                        return;
                    }
                }
            }
        }

        // Find the last use statement and insert after it
        $lastUseIndex = -1;
        foreach ($stmts as $i => $stmt) {
            if ($stmt instanceof Use_) {
                $lastUseIndex = $i;
            }
        }

        $newUse = new Use_([new UseUse(new Name($fqcn))]);

        if ($lastUseIndex >= 0) {
            array_splice($stmts, $lastUseIndex + 1, 0, [$newUse]);
        } else {
            // No use statements found; insert at position 1 (after namespace/declare)
            array_splice($stmts, 1, 0, [$newUse]);
        }
    }

    private function relativePath(string $fullPath): string
    {
        return str_replace(base_path() . '/', '', $fullPath);
    }
}
