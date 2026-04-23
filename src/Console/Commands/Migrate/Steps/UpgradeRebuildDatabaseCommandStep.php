<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps;

use Illuminate\Support\Facades\File;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Concerns\TracksChanges;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Contracts\MigrationStep;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationContext;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Convert a pre-chassis `extends Command` RebuildDatabaseCommand into a thin
 * subclass of `Northwestern\SysDev\Chassis\Console\Commands\RebuildDatabaseCommand`.
 *
 * Recognizes the canonical pre-chassis shape produced by the starter (and its
 * forks): a class that extends `Command`, uses `RunsSteps`, and defines a
 * `handle()` method with an inline step array whose first entries match the
 * chassis base steps. Drops the base steps, moves app-specific steps into
 * `appendSteps()`, removes inherited methods (`handle`, `clearCache`,
 * `successMessage`), and keeps `displayPostBuildInfo()` as-is.
 *
 * Skips the file if the class already extends the chassis base, if the shape
 * doesn't match (no `handle()`, no recognizable step array), or if the base
 * steps aren't present in canonical order.
 */
class UpgradeRebuildDatabaseCommandStep implements MigrationStep
{
    use TracksChanges;

    private const string TARGET_FILE = 'app/Console/Commands/RebuildDatabaseCommand.php';

    private const string CHASSIS_BASE_FQCN = 'Northwestern\\SysDev\\Chassis\\Console\\Commands\\RebuildDatabaseCommand';

    /**
     * Canonical base step keys provided by the chassis base class, in the
     * order they appear. Used to recognize and drop duplicates from the
     * app's inline steps array.
     *
     * @var list<string>
     */
    private const array BASE_STEP_KEYS = [
        'Clearing cache',
        'Clearing queue',
        'Clearing schedule cache',
        'Running migrations',
        'Seeding database',
    ];

    public function label(): string
    {
        return 'Upgrading RebuildDatabaseCommand...';
    }

    public function run(MigrationContext $context): void
    {
        $path = base_path(self::TARGET_FILE);

        if (! File::exists($path)) {
            return;
        }

        $code = File::get($path);
        $parser = new ParserFactory()->createForNewestSupportedVersion();
        $oldStmts = $parser->parse($code);

        if ($oldStmts === null) {
            return;
        }

        $classNode = $this->findClass($oldStmts);
        if (! $classNode instanceof Class_) {
            return;
        }

        // Already migrated? Skip.
        if ($this->extendsChassisBase($classNode)) {
            return;
        }

        // Doesn't extend Command at all? Shape isn't what we know how to migrate.
        if (! $this->extendsCommand($classNode)) {
            $context->conflicts[] = self::TARGET_FILE . ' extends an unexpected base class; skipping';

            return;
        }

        $handleMethod = $this->findMethod($classNode, 'handle');
        if (! $handleMethod instanceof ClassMethod) {
            $context->conflicts[] = self::TARGET_FILE . ' has no handle() method; skipping';

            return;
        }

        $stepsArray = $this->extractStepsArray($handleMethod);
        if (! $stepsArray instanceof Array_) {
            $context->conflicts[] = self::TARGET_FILE . ' has no recognizable $steps array; skipping';

            return;
        }

        $appStepCount = $this->countAppSteps($stepsArray);
        if ($appStepCount === null) {
            $context->conflicts[] = self::TARGET_FILE . ' does not start with the canonical chassis base steps; skipping';

            return;
        }

        // Clone for format-preserving printing.
        $cloneTraverser = new NodeTraverser();
        $cloneTraverser->addVisitor(new CloningVisitor());
        /** @var list<Node\Stmt> $newStmts */
        $newStmts = $cloneTraverser->traverse($oldStmts);

        $clonedClass = $this->findClass($newStmts);
        if (! $clonedClass instanceof Class_) {
            return;
        }

        $this->rewriteClass($clonedClass);
        $this->rewriteImports($newStmts);

        $printer = new Standard();
        $newCode = $printer->printFormatPreserving($newStmts, $oldStmts, $parser->getTokens());

        if (! $context->isDryRun) {
            File::put($path, $newCode);
        }

        $this->incrementCounter($context, 'filesScaffolded');
        $context->command->line(
            '  <fg=green>✓</> ' . self::TARGET_FILE . ' (extends chassis base, '
            . $appStepCount . ' app step' . ($appStepCount === 1 ? '' : 's') . ' moved to appendSteps())'
        );
    }

    /**
     * @param  array<Node\Stmt>  $stmts
     */
    private function findClass(array $stmts): ?Class_
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

    private function extendsChassisBase(Class_ $class): bool
    {
        if (! $class->extends instanceof Name) {
            return false;
        }

        $name = $class->extends->toString();

        return $name === self::CHASSIS_BASE_FQCN
            || str_ends_with($name, '\\RebuildDatabaseCommand');
    }

    private function extendsCommand(Class_ $class): bool
    {
        if (! $class->extends instanceof Name) {
            return false;
        }

        $name = $class->extends->toString();

        return $name === 'Command' || str_ends_with($name, '\\Command');
    }

    private function findMethod(Class_ $class, string $name): ?ClassMethod
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof ClassMethod && $stmt->name->toString() === $name) {
                return $stmt;
            }
        }

        return null;
    }

    /**
     * Find the `$steps = [...]` array literal inside handle(). Returns null
     * if the method body doesn't contain one.
     */
    private function extractStepsArray(ClassMethod $handle): ?Array_
    {
        if ($handle->stmts === null) {
            return null;
        }

        foreach ($handle->stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\Expression) {
                continue;
            }
            if (! $stmt->expr instanceof Node\Expr\Assign) {
                continue;
            }
            if (! $stmt->expr->var instanceof Node\Expr\Variable) {
                continue;
            }
            if ($stmt->expr->var->name !== 'steps') {
                continue;
            }
            if ($stmt->expr->expr instanceof Array_) {
                return $stmt->expr->expr;
            }
        }

        return null;
    }

    /**
     * Count the app-specific step entries in the array. Returns null if the
     * shape doesn't match what we know how to migrate (fewer than the base
     * step count, or the first N entries aren't the canonical base keys).
     */
    private function countAppSteps(Array_ $steps): ?int
    {
        $items = array_values($steps->items);
        $baseCount = count(self::BASE_STEP_KEYS);

        if (count($items) < $baseCount) {
            return null;
        }

        foreach (self::BASE_STEP_KEYS as $i => $expectedKey) {
            $item = $items[$i];
            if (! $item->key instanceof Node\Scalar\String_ || $item->key->value !== $expectedKey) {
                return null;
            }
        }

        return count($items) - $baseCount;
    }

    private function rewriteClass(Class_ $class): void
    {
        // 1. Change extends to fully-qualified chassis base.
        $class->extends = new Name('\\' . self::CHASSIS_BASE_FQCN);

        // 2. Find the cloned steps array and strip base-step entries, leaving
        //    only app-specific ones. Capture before removing handle().
        $appendStepsMethod = null;
        $handle = $this->findMethod($class, 'handle');
        if ($handle instanceof ClassMethod) {
            $stepsArray = $this->extractStepsArray($handle);
            if ($stepsArray instanceof Array_) {
                $stepsArray->items = array_slice($stepsArray->items, count(self::BASE_STEP_KEYS));
                if ($stepsArray->items !== []) {
                    $appendStepsMethod = $this->buildAppendStepsMethod($stepsArray);
                }
            }
        }

        // 3. Remove inherited members: use RunsSteps, handle(), clearCache(),
        //    successMessage() (if it returns the chassis default string).
        $class->stmts = array_values(array_filter($class->stmts, function (Node $stmt): bool {
            if ($stmt instanceof TraitUse) {
                foreach ($stmt->traits as $trait) {
                    $name = $trait->toString();
                    if ($name === 'RunsSteps' || str_ends_with($name, '\\RunsSteps')) {
                        return false;
                    }
                }
            }

            if ($stmt instanceof ClassMethod) {
                $method = $stmt->name->toString();
                if ($method === 'handle' || $method === 'clearCache') {
                    return false;
                }
                if ($method === 'successMessage' && $this->isDefaultSuccessMessage($stmt)) {
                    return false;
                }
            }

            return true;
        }));

        // 4. Insert appendSteps() method right after the last property (or at
        //    the end if the class has no properties). This keeps the
        //    properties → methods ordering readers expect.
        if ($appendStepsMethod instanceof ClassMethod) {
            $insertAt = $this->findInsertionIndex($class->stmts);
            array_splice($class->stmts, $insertAt, 0, [$appendStepsMethod]);
        }
    }

    /**
     * Return the index at which a new method should be inserted so it lands
     * after all traits, constants, and properties but before any other methods.
     *
     * @param  array<Node\Stmt>  $stmts
     */
    private function findInsertionIndex(array $stmts): int
    {
        $lastNonMethod = -1;

        foreach ($stmts as $i => $stmt) {
            if ($stmt instanceof ClassMethod) {
                continue;
            }
            $lastNonMethod = $i;
        }

        return $lastNonMethod + 1;
    }

    private function isDefaultSuccessMessage(ClassMethod $method): bool
    {
        if ($method->stmts === null || count($method->stmts) !== 1) {
            return false;
        }

        $return = $method->stmts[0];
        if (! $return instanceof Return_) {
            return false;
        }

        return $return->expr instanceof Node\Scalar\String_
            && $return->expr->value === 'Database rebuild complete';
    }

    /**
     * Build the appendSteps() method. Takes the (already-pruned) steps array
     * from the cloned tree so its formatting and line breaks are preserved.
     */
    private function buildAppendStepsMethod(Array_ $appSteps): ClassMethod
    {
        $method = new ClassMethod('appendSteps', [
            'flags' => \PhpParser\Modifiers::PROTECTED,
            'returnType' => new Node\Identifier('array'),
            'stmts' => [new Return_($appSteps)],
        ]);

        $method->setDocComment(new \PhpParser\Comment\Doc(
            "/**\n"
            . "     * @return array<string, callable(): mixed>\n"
            . '     */'
        ));

        return $method;
    }

    /**
     * Strip imports that are no longer needed (Command, RunsSteps, Throwable)
     * if nothing else in the file references them.
     *
     * @param  list<Node\Stmt>  $stmts
     */
    private function rewriteImports(array &$stmts): void
    {
        $candidates = [
            'Illuminate\\Console\\Command',
            'Northwestern\\SysDev\\Chassis\\Console\\Concerns\\RunsSteps',
            'App\\Console\\Commands\\Concerns\\RunsSteps',
            'Throwable',
        ];

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_) {
                $stmt->stmts = $this->filterImports($stmt->stmts, $candidates);

                return;
            }
        }

        $stmts = $this->filterImports($stmts, $candidates);
    }

    /**
     * @param  array<Node\Stmt>  $stmts
     * @param  list<string>  $candidates
     * @return list<Node\Stmt>
     */
    private function filterImports(array $stmts, array $candidates): array
    {
        return array_values(array_filter($stmts, function (Node $stmt) use ($candidates): bool {
            if (! $stmt instanceof Use_) {
                return true;
            }
            foreach ($stmt->uses as $use) {
                if (in_array($use->name->toString(), $candidates, true)) {
                    return false;
                }
            }

            return true;
        }));
    }
}
