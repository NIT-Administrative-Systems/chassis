<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps;

use Illuminate\Support\Facades\File;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Concerns\InteractsWithAst;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationContext;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\Use_;
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
class UpgradeRebuildDatabaseCommandStep extends AbstractMigrationStep
{
    use InteractsWithAst;

    private const string TARGET_FILE = 'app/Console/Commands/RebuildDatabaseCommand.php';

    private const string CHASSIS_BASE_FQCN = 'Northwestern\\SysDev\\Chassis\\Console\\Commands\\RebuildDatabaseCommand';

    private const string CHASSIS_BASE_ALIAS = 'BaseRebuildDatabaseCommand';

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
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $oldStmts = $parser->parse($code);

        if ($oldStmts === null) {
            return;
        }

        $classNode = $this->findFirstClassStatement($oldStmts);
        if (! $classNode instanceof Class_) {
            return;
        }

        // Already migrated? Skip.
        if ($this->alreadyExtendsChassisBase($classNode)) {
            return;
        }

        // Doesn't extend Command at all? Shape isn't what we know how to migrate.
        if (! $this->extendsIlluminateCommand($classNode)) {
            $this->recordConflict($context, self::TARGET_FILE . ' extends an unexpected base class; skipping');

            return;
        }

        $handleMethod = $this->findClassMethod($classNode, 'handle');
        if (! $handleMethod instanceof ClassMethod) {
            $this->recordConflict($context, self::TARGET_FILE . ' has no handle() method; skipping');

            return;
        }

        $stepsArray = $this->extractHandleStepsArray($handleMethod);
        $linearRewritePlan = null;
        $appStepCount = null;

        if ($stepsArray instanceof Array_) {
            $appStepCount = $this->countCustomAppSteps($stepsArray);
            if ($appStepCount === null) {
                $this->recordConflict($context, self::TARGET_FILE . ' does not start with the canonical chassis base steps; skipping');

                return;
            }
        } else {
            $linearRewritePlan = $this->extractLinearRewritePlan($handleMethod);
            if ($linearRewritePlan === null) {
                $this->recordConflict($context, self::TARGET_FILE . ' has no recognizable $steps array; skipping');

                return;
            }

            $appStepCount = count($linearRewritePlan['extra_steps']);
        }

        // Clone for format-preserving printing.
        $newStmts = $this->cloneStatementTree($oldStmts);

        $clonedClass = $this->findFirstClassStatement($newStmts);
        if (! $clonedClass instanceof Class_) {
            return;
        }

        $this->ensureBaseClassAliasImport($newStmts);

        if ($linearRewritePlan === null) {
            $this->rewriteCommandClass($clonedClass);
        } else {
            $clonedHandleMethod = $this->findClassMethod($clonedClass, 'handle');
            if (! $clonedHandleMethod instanceof ClassMethod) {
                return;
            }

            $clonedLinearRewritePlan = $this->extractLinearRewritePlan($clonedHandleMethod);
            if ($clonedLinearRewritePlan === null) {
                return;
            }

            $this->rewriteLinearCommandClass($clonedClass, $clonedLinearRewritePlan);
        }

        $this->removeObsoleteImports($newStmts);

        $printer = new Standard();
        $newCode = $this->printWithOriginalFormatting($printer, $parser, $newStmts, $oldStmts);

        if (! $context->isDryRun) {
            File::put($path, $newCode);
        }

        $this->markFileModified($context);
        $this->success(
            $context,
            self::TARGET_FILE . ' (extends chassis base, '
            . $appStepCount . ' app step' . ($appStepCount === 1 ? '' : 's') . ' moved to appendSteps())'
        );
    }

    private function alreadyExtendsChassisBase(Class_ $class): bool
    {
        if (! $class->extends instanceof Name) {
            return false;
        }

        $name = $class->extends->toString();

        return $name === self::CHASSIS_BASE_FQCN
            || str_ends_with($name, '\\RebuildDatabaseCommand');
    }

    private function extendsIlluminateCommand(Class_ $class): bool
    {
        if (! $class->extends instanceof Name) {
            return false;
        }

        $name = $class->extends->toString();

        return $name === 'Command' || str_ends_with($name, '\\Command');
    }

    private function findClassMethod(Class_ $class, string $name): ?ClassMethod
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
    private function extractHandleStepsArray(ClassMethod $handle): ?Array_
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
            if (! $stmt->expr->var instanceof Variable) {
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
    private function countCustomAppSteps(Array_ $steps): ?int
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

    private function rewriteCommandClass(Class_ $class): void
    {
        // 1. Change extends to the aliased chassis base import.
        $class->extends = new Name(self::CHASSIS_BASE_ALIAS);

        // 2. Find the cloned steps array and strip base-step entries, leaving
        //    only app-specific ones. Capture before removing handle().
        $appendStepsMethod = null;
        $handle = $this->findClassMethod($class, 'handle');
        if ($handle instanceof ClassMethod) {
            $stepsArray = $this->extractHandleStepsArray($handle);
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
     * Recognize the legacy "linear" handle() flow used by some apps:
     * confirm prompt, direct cache/queue/migrate calls, then app-specific
     * follow-up commands and post-build notices.
     *
     * @return array{
     *     preserve_confirm_prompt: bool,
     *     extra_steps: list<Node\Stmt\Expression>,
     *     post_build_stmts: list<Node\Stmt>,
     * }|null
     */
    private function extractLinearRewritePlan(ClassMethod $handle): ?array
    {
        if ($handle->stmts === null) {
            return null;
        }

        $statements = $this->withoutNops($handle->stmts);

        if ($statements === []) {
            return null;
        }

        $offset = 0;
        $preserveConfirmPrompt = false;

        if (isset($statements[$offset]) && $this->isConfirmToProceedGuard($statements[$offset])) {
            $preserveConfirmPrompt = true;
            $offset++;
        }

        if (isset($statements[$offset]) && $this->isClearCacheTryCatch($statements[$offset])) {
            $offset++;
        }

        if (! isset($statements[$offset], $statements[$offset + 1])) {
            return null;
        }

        if (! $this->isCommandInvocation($statements[$offset], 'call', 'queue:clear')) {
            return null;
        }

        if (! $this->isMigrateFreshAndSeedInvocation($statements[$offset + 1])) {
            return null;
        }

        $offset += 2;
        $extraSteps = [];

        while (isset($statements[$offset]) && $statements[$offset] instanceof Node\Stmt\Expression) {
            $statement = $statements[$offset];

            if ($this->isSuccessMessageStatement($statement)) {
                break;
            }

            if (! $this->isSupportedExtraStepInvocation($statement)) {
                return null;
            }

            $extraSteps[] = $statement;
            $offset++;
        }

        if (! isset($statements[$offset]) || ! $this->isSuccessMessageStatement($statements[$offset])) {
            return null;
        }

        $offset++;
        $postBuildStatements = [];

        while (isset($statements[$offset])) {
            $statement = $statements[$offset];

            if ($this->isSuccessReturnStatement($statement)) {
                break;
            }

            $postBuildStatements[] = $statement;
            $offset++;
        }

        if (! isset($statements[$offset]) || ! $this->isSuccessReturnStatement($statements[$offset])) {
            return null;
        }

        return [
            'preserve_confirm_prompt' => $preserveConfirmPrompt,
            'extra_steps' => $extraSteps,
            'post_build_stmts' => $postBuildStatements,
        ];
    }

    /**
     * @param  array{
     *     preserve_confirm_prompt: bool,
     *     extra_steps: list<Node\Stmt\Expression>,
     *     post_build_stmts: list<Node\Stmt>,
     * }  $plan
     */
    private function rewriteLinearCommandClass(Class_ $class, array $plan): void
    {
        $class->extends = new Name(self::CHASSIS_BASE_ALIAS);

        $newMethods = [];

        if ($plan['preserve_confirm_prompt']) {
            $newMethods[] = $this->buildConfirmingHandleMethod();
        }

        if ($plan['extra_steps'] !== []) {
            $newMethods[] = $this->buildAppendStepsMethodFromExpressions($plan['extra_steps']);
        }

        if ($plan['post_build_stmts'] !== []) {
            $newMethods[] = $this->buildDisplayPostBuildInfoMethod($plan['post_build_stmts']);
        }

        $class->stmts = array_values(array_filter($class->stmts, function (Node $stmt): bool {
            if ($stmt instanceof ClassMethod) {
                return ! in_array($stmt->name->toString(), ['handle', 'clearCache', 'displayPostBuildInfo', 'successMessage'], true);
            }

            return true;
        }));

        if ($newMethods === []) {
            return;
        }

        $insertAt = $this->findInsertionIndex($class->stmts);
        array_splice($class->stmts, $insertAt, 0, $newMethods);
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

    private function isConfirmToProceedGuard(Node\Stmt $statement): bool
    {
        if (! $statement instanceof If_) {
            return false;
        }

        if (! $statement->cond instanceof Node\Expr\BooleanNot) {
            return false;
        }

        $condition = $statement->cond->expr;

        return $condition instanceof MethodCall
            && $condition->var instanceof Variable
            && $condition->var->name === 'this'
            && $condition->name instanceof Identifier
            && $condition->name->toString() === 'confirmToProceed';
    }

    private function isClearCacheTryCatch(Node\Stmt $statement): bool
    {
        if (! $statement instanceof TryCatch || count($statement->stmts) !== 1) {
            return false;
        }

        return $this->isCommandInvocation($statement->stmts[0], 'call', 'cache:clear');
    }

    private function isMigrateFreshAndSeedInvocation(Node\Stmt $statement): bool
    {
        if (! $statement instanceof Node\Stmt\Expression) {
            return false;
        }

        $expression = $statement->expr;
        if (! $expression instanceof MethodCall) {
            return false;
        }

        if (! $this->isThisMethodCall($expression, 'call')) {
            return false;
        }

        $commandArgument = $this->methodCallArgumentValue($expression, 0);
        $optionsArgument = $this->methodCallArgumentValue($expression, 1);

        if (! $commandArgument instanceof Node\Scalar\String_ || ! $optionsArgument instanceof Array_) {
            return false;
        }

        if ($commandArgument->value !== 'migrate:fresh') {
            return false;
        }

        foreach ($optionsArgument->items as $item) {
            if (! $item instanceof ArrayItem) {
                continue;
            }

            if ($item->key instanceof Node\Scalar\String_
                && $item->key->value === '--seed'
                && $item->value instanceof Node\Expr\ConstFetch
                && strtolower($item->value->name->toString()) === 'true'
            ) {
                return true;
            }
        }

        return false;
    }

    private function isSupportedExtraStepInvocation(Node\Stmt\Expression $statement): bool
    {
        return $this->extractCommandInvocation($statement) instanceof MethodCall;
    }

    private function isSuccessMessageStatement(Node\Stmt $statement): bool
    {
        if (! $statement instanceof Node\Stmt\Expression) {
            return false;
        }

        $expression = $statement->expr;
        if (! $expression instanceof MethodCall) {
            return false;
        }

        if (! $expression->var instanceof Node\Expr\PropertyFetch) {
            return false;
        }

        $propertyFetch = $expression->var;

        return $propertyFetch->var instanceof Variable
            && $propertyFetch->var->name === 'this'
            && $propertyFetch->name instanceof Identifier
            && $propertyFetch->name->toString() === 'components'
            && $expression->name instanceof Identifier
            && $expression->name->toString() === 'success';
    }

    private function isSuccessReturnStatement(Node\Stmt $statement): bool
    {
        if (! $statement instanceof Return_) {
            return false;
        }

        return $statement->expr instanceof Node\Expr\ClassConstFetch
            && $statement->expr->class instanceof Name
            && in_array($statement->expr->class->toString(), ['self', 'static'], true)
            && $statement->expr->name instanceof Identifier
            && $statement->expr->name->toString() === 'SUCCESS';
    }

    private function isCommandInvocation(Node\Stmt $statement, string $methodName, string $command): bool
    {
        if (! $statement instanceof Node\Stmt\Expression) {
            return false;
        }

        $expression = $statement->expr;
        if (! $expression instanceof MethodCall || ! $this->isThisMethodCall($expression, $methodName)) {
            return false;
        }

        $commandArgument = $this->methodCallArgumentValue($expression, 0);

        return $commandArgument instanceof Node\Scalar\String_
            && $commandArgument->value === $command;
    }

    private function isThisMethodCall(MethodCall $methodCall, string $methodName): bool
    {
        return $methodCall->var instanceof Variable
            && $methodCall->var->name === 'this'
            && $methodCall->name instanceof Identifier
            && $methodCall->name->toString() === $methodName;
    }

    /**
     * Build the appendSteps() method. Takes the (already-pruned) steps array
     * from the cloned tree so its formatting and line breaks are preserved.
     */
    private function buildAppendStepsMethod(Array_ $appSteps): ClassMethod
    {
        $method = new ClassMethod('appendSteps', [
            'flags' => \PhpParser\Modifiers::PROTECTED,
            'returnType' => new Identifier('array'),
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
     * @param  list<Node\Stmt\Expression>  $statements
     */
    private function buildAppendStepsMethodFromExpressions(array $statements): ClassMethod
    {
        $items = [];

        foreach ($statements as $statement) {
            $commandInvocation = $this->extractCommandInvocation($statement);
            if (! $commandInvocation instanceof MethodCall) {
                continue;
            }

            $items[] = new ArrayItem(
                new ArrowFunction([
                    'expr' => $commandInvocation,
                ]),
                new Node\Scalar\String_($this->stepLabelForCommandInvocation($commandInvocation)),
            );
        }

        return $this->buildAppendStepsMethod(new Array_($items, ['kind' => Array_::KIND_SHORT]));
    }

    /**
     * @param  list<Node\Stmt>  $postBuildStatements
     */
    private function buildDisplayPostBuildInfoMethod(array $postBuildStatements): ClassMethod
    {
        return new ClassMethod('displayPostBuildInfo', [
            'flags' => \PhpParser\Modifiers::PROTECTED,
            'returnType' => new Identifier('void'),
            'stmts' => $postBuildStatements,
        ]);
    }

    private function buildConfirmingHandleMethod(): ClassMethod
    {
        $parentHandleCall = new StaticCall(new Name('parent'), 'handle');

        return new ClassMethod('handle', [
            'flags' => \PhpParser\Modifiers::PUBLIC,
            'returnType' => new Identifier('int'),
            'stmts' => [
                new If_(
                    new Node\Expr\BooleanNot(new MethodCall(new Variable('this'), 'confirmToProceed')),
                    [
                        'stmts' => [
                            new Node\Stmt\Expression(
                                new MethodCall(
                                    new Node\Expr\PropertyFetch(new Variable('this'), 'components'),
                                    'warn',
                                    [new Arg(new Node\Scalar\String_('Database rebuild cancelled.'))],
                                ),
                            ),
                            new Return_(
                                new Node\Expr\ClassConstFetch(new Name('self'), 'FAILURE'),
                            ),
                        ],
                    ],
                ),
                new Return_($parentHandleCall),
            ],
        ]);
    }

    private function extractCommandInvocation(Node\Stmt\Expression $statement): ?MethodCall
    {
        $expression = $statement->expr;

        if (! $expression instanceof MethodCall) {
            return null;
        }

        if (! $expression->name instanceof Identifier) {
            return null;
        }

        if (! in_array($expression->name->toString(), ['call', 'callSilent', 'callSilently'], true)) {
            return null;
        }

        if (! $this->methodCallArgumentValue($expression, 0) instanceof Node\Scalar\String_) {
            return null;
        }

        return $expression;
    }

    private function stepLabelForCommandInvocation(MethodCall $commandInvocation): string
    {
        $commandName = $this->methodCallArgumentValue($commandInvocation, 0);
        if (! $commandName instanceof Node\Scalar\String_) {
            return 'Running command';
        }

        if ($commandName->value === 'db:seed') {
            $className = $this->arrayArgumentStringValue($commandInvocation, '--class');

            return $className === null
                ? 'Seeding database'
                : "Seeding {$className}";
        }

        if ($commandName->value === 'ide-helper:models') {
            return 'Generating IDE helper models';
        }

        return "Running {$commandName->value}";
    }

    private function arrayArgumentStringValue(MethodCall $commandInvocation, string $option): ?string
    {
        $optionsArgument = $this->methodCallArgumentValue($commandInvocation, 1);

        if (! $optionsArgument instanceof Array_) {
            return null;
        }

        foreach ($optionsArgument->items as $item) {
            if (! $item instanceof ArrayItem) {
                continue;
            }

            if ($item->key instanceof Node\Scalar\String_
                && $item->key->value === $option
                && $item->value instanceof Node\Scalar\String_
            ) {
                return $item->value->value;
            }
        }

        return null;
    }

    /**
     * Strip imports that are no longer needed (Command, RunsSteps, Throwable)
     * if nothing else in the file references them.
     *
     * @param  array<Node\Stmt>  $stmts
     */
    private function removeObsoleteImports(array &$stmts): void
    {
        $candidates = [
            'Illuminate\\Console\\Command',
            'Northwestern\\SysDev\\Chassis\\Console\\Concerns\\RunsSteps',
            'App\\Console\\Commands\\Concerns\\RunsSteps',
            'Throwable',
        ];

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_) {
                $stmt->stmts = $this->filterRemovableImports($stmt->stmts, $candidates);

                return;
            }
        }

        $stmts = $this->filterRemovableImports($stmts, $candidates);
    }

    /**
     * @param  array<Node\Stmt>  $stmts
     */
    private function ensureBaseClassAliasImport(array &$stmts): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_) {
                $this->insertBaseClassAliasImportIntoStatements($stmt->stmts);

                return;
            }
        }

        $this->insertBaseClassAliasImportIntoStatements($stmts);
    }

    /**
     * @param  array<Node\Stmt>  $stmts
     */
    private function insertBaseClassAliasImportIntoStatements(array &$stmts): void
    {
        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Use_) {
                continue;
            }

            foreach ($stmt->uses as $use) {
                if ($use->name->toString() === self::CHASSIS_BASE_FQCN
                    && $use->alias?->toString() === self::CHASSIS_BASE_ALIAS
                ) {
                    return;
                }
            }
        }

        $lastUseIndex = -1;

        foreach ($stmts as $i => $stmt) {
            if ($stmt instanceof Use_) {
                $lastUseIndex = $i;
            }
        }

        $baseImport = new Use_([
            new Node\UseItem(
                new Name(self::CHASSIS_BASE_FQCN),
                new Identifier(self::CHASSIS_BASE_ALIAS),
            ),
        ]);

        if ($lastUseIndex >= 0) {
            array_splice($stmts, $lastUseIndex + 1, 0, [$baseImport]);

            return;
        }

        array_splice($stmts, 1, 0, [$baseImport]);
    }

    /**
     * @param  array<Node\Stmt>  $statements
     * @return list<Node\Stmt>
     */
    private function withoutNops(array $statements): array
    {
        return array_values(array_filter(
            $statements,
            static fn (Node\Stmt $statement): bool => ! $statement instanceof Nop,
        ));
    }

    private function methodCallArgumentValue(MethodCall $methodCall, int $argumentIndex): ?Node\Expr
    {
        $argument = $methodCall->args[$argumentIndex] ?? null;

        if (! $argument instanceof Arg) {
            return null;
        }

        return $argument->value;
    }

    /**
     * @param  array<Node\Stmt>  $stmts
     * @param  list<string>  $candidates
     * @return list<Node\Stmt>
     */
    private function filterRemovableImports(array $stmts, array $candidates): array
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
