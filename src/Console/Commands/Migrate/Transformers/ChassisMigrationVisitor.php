<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Transformers;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeVisitorAbstract;

/**
 * AST node visitor that performs all chassis migration transformations:
 *
 * 1. Rewrites `use` import statements from old namespaces to new ones
 * 2. Rewrites inline class references: new X, extends X, implements X, instanceof X, X::class
 * 3. Rewrites fully-qualified name references (\Old\Namespace\Class)
 * 4. Transforms DateTimeFormatter::datetime() calls: $user arg → $user->timezone
 * 5. Renames #[StarterValidator(...)] attributes to #[ValidatesConfig(...)]
 *
 * @internal Used only by MigrateToChassisCommand
 */
class ChassisMigrationVisitor extends NodeVisitorAbstract
{
    private int $changeCount = 0;

    /**
     * Maps old short class names to their new short class names,
     * but only when the old FQCN differs from the new one in the short name.
     *
     * @var array<string, string>
     */
    // Build a map of short name changes (e.g. StarterValidator → ValidatesConfig)
    private array $shortNameRenames = [];

    /**
     * Maps old FQCNs (as backslash-separated strings) to new FQCNs.
     *
     * @var array<string, string>
     */
    private array $renames;

    /**
     * Maps old short class names to their old FQCNs, for classes where
     * the short name did NOT change. Used to detect same-namespace resolution.
     *
     * @var array<string, string>
     */
    // Build a reverse map for same-namespace resolution: short name → old FQCN
    // Only for renames where the short name stayed the same
    private array $sameShortNameToOldFqcn = [];

    /**
     * The current file's namespace, tracked from Namespace_ nodes.
     */
    private ?string $currentNamespace = null;

    /** @var list<array{string, int, string}> */
    private array $changes = [];

    /**
     * Use statements to add after traversal (for same-namespace resolution fixes).
     *
     * @var list<string>
     */
    private array $pendingUseStatements = [];

    /**
     * @param  array<string, string>  $classRenames  Old FQCN => New FQCN
     * @param  string  $filePath  Relative file path for change reporting
     */
    public function __construct(
        array $classRenames,
        private readonly string $filePath,
    ) {
        $this->renames = $classRenames;
        foreach ($classRenames as $old => $new) {
            $oldShort = $this->shortName($old);
            $newShort = $this->shortName($new);
            if ($oldShort !== $newShort) {
                $this->shortNameRenames[$oldShort] = $newShort;
            } else {
                $this->sameShortNameToOldFqcn[$oldShort] = $old;
            }
        }
    }

    public function enterNode(Node $node): Node|int|null
    {
        // Track the current file's namespace for same-namespace resolution
        if ($node instanceof Namespace_ && $node->name instanceof Name) {
            $this->currentNamespace = $node->name->toString();
        }

        // 1. Rewrite use statements: use Old\Namespace\Class;
        if ($node instanceof Use_) {
            return $this->rewriteUseStatement($node);
        }

        // 2. Rewrite Name nodes (covers extends, implements, new, instanceof, ::class, type hints, etc.)
        if ($node instanceof Name && ! $node instanceof FullyQualified) {
            return $this->rewriteName($node);
        }

        // 3. Rewrite fully-qualified names: \Old\Namespace\Class
        if ($node instanceof FullyQualified) {
            return $this->rewriteFullyQualifiedName($node);
        }

        // 4. Transform DateTimeFormatter::datetime() second arg: $user → $user->timezone
        if ($node instanceof MethodCall) {
            return $this->transformDatetimeCall($node);
        }

        // 5. Rewrite #[StarterValidator(...)] → #[ValidatesConfig(...)]
        if ($node instanceof Attribute) {
            return $this->rewriteAttribute($node);
        }

        return null;
    }

    public function getChangeCount(): int
    {
        return $this->changeCount;
    }

    /**
     * @return list<array{string, int, string}>
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /**
     * FQCNs that need use statements added (from same-namespace resolution fixes).
     *
     * @return list<string>
     */
    public function getPendingUseStatements(): array
    {
        return array_values(array_unique($this->pendingUseStatements));
    }

    /**
     * Rewrite a `use` import statement if its FQCN matches a rename entry.
     */
    private function rewriteUseStatement(Use_ $node): ?Use_
    {
        $changed = false;

        foreach ($node->uses as $use) {
            $oldFqcn = $use->name->toString();

            if (! isset($this->renames[$oldFqcn])) {
                continue;
            }

            $newFqcn = $this->renames[$oldFqcn];
            $use->name = new Name($newFqcn, $use->name->getAttributes());

            // If the short class name changed and there's no alias, add one or update
            $oldShort = $this->shortName($oldFqcn);
            $newShort = $this->shortName($newFqcn);

            if ($oldShort !== $newShort && $use->alias === null) {
                // No alias needed — the consuming code should be updated to use the new name
            }

            $this->recordChange($use->getStartLine(), "use {$oldFqcn} → {$newFqcn}");
            $changed = true;
        }

        return $changed ? $node : null;
    }

    /**
     * Rewrite a non-fully-qualified Name node if it matches a known old short name
     * that changed during the rename (e.g. StarterValidator → ValidatesConfig).
     *
     * This handles: extends X, implements X, new X, instanceof X, X::class, type hints.
     */
    private function rewriteName(Name $node): ?Name
    {
        $name = $node->toString();

        // Check if this is a short name that was renamed
        if (isset($this->shortNameRenames[$name])) {
            $newName = $this->shortNameRenames[$name];
            $this->recordChange($node->getStartLine(), "{$name} → {$newName}");

            return new Name($newName, $node->getAttributes());
        }

        // Check if the full string matches a rename key (for multi-part unqualified names)
        if (isset($this->renames[$name])) {
            $newFqcn = $this->renames[$name];
            $this->recordChange($node->getStartLine(), "{$name} → {$newFqcn}");

            return new Name($newFqcn, $node->getAttributes());
        }

        // Check for same-namespace resolution: when a class like BaseModel is used without
        // a use statement because it's in the same namespace as the current file.
        // e.g. `extends BaseModel` in App\Domains\Core\Models resolves to
        // App\Domains\Core\Models\BaseModel via PHP's same-namespace resolution.
        if ($this->currentNamespace !== null && isset($this->sameShortNameToOldFqcn[$name])) {
            $resolvedFqcn = $this->currentNamespace . '\\' . $name;
            $oldFqcn = $this->sameShortNameToOldFqcn[$name];

            if ($resolvedFqcn === $oldFqcn) {
                $newFqcn = $this->renames[$oldFqcn];
                $this->recordChange($node->getStartLine(), "{$name} → \\{$newFqcn} (same-namespace resolution)");

                // Can't return FullyQualified — format-preserving printing requires same node type.
                // Instead, track the use statement to add and keep the short name.
                $this->pendingUseStatements[] = $newFqcn;

                return $node;
            }
        }

        return null;
    }

    /**
     * Rewrite a fully-qualified name (\Old\Namespace\Class) to the new FQCN.
     */
    private function rewriteFullyQualifiedName(FullyQualified $node): ?FullyQualified
    {
        $fqcn = $node->toString();

        if (! isset($this->renames[$fqcn])) {
            return null;
        }

        $newFqcn = $this->renames[$fqcn];
        $this->recordChange($node->getStartLine(), "\\{$fqcn} → \\{$newFqcn}");

        return new FullyQualified($newFqcn, $node->getAttributes());
    }

    /**
     * Transform DateTimeFormatter::datetime() calls where the second argument
     * is a variable (likely a User model) rather than a string literal.
     *
     * Before: $formatter->datetime($date, $user)
     * After:  $formatter->datetime($date, $user->timezone)
     */
    private function transformDatetimeCall(MethodCall $node): ?MethodCall
    {
        // Match ->datetime(...) calls with at least 2 arguments
        if (! $node->name instanceof Identifier || $node->name->name !== 'datetime') {
            return null;
        }

        if (count($node->args) < 2) {
            return null;
        }

        $secondArg = $node->args[1];
        if (! $secondArg instanceof Arg) {
            return null;
        }

        $argValue = $secondArg->value;

        // Only transform if the second arg is a variable or property fetch (not a string literal)
        if ($argValue instanceof String_) {
            return null;
        }

        // Transform $user → $user->timezone or $this->user → $this->user->timezone
        if (! $argValue instanceof Variable && ! $argValue instanceof PropertyFetch) {
            return null;
        }

        $secondArg->value = new PropertyFetch(
            $argValue,
            new Identifier('timezone'),
            $argValue->getAttributes(),
        );

        $varName = match (true) {
            $argValue instanceof Variable && is_string($argValue->name) => '$' . $argValue->name,
            $argValue instanceof PropertyFetch && $argValue->name instanceof Identifier => '...->' . $argValue->name->name,
            default => '(expr)',
        };
        $this->recordChange(
            $node->getStartLine(),
            "datetime() 2nd arg: {$varName} → {$varName}->timezone",
        );

        return $node;
    }

    /**
     * Rewrite #[StarterValidator(...)] to #[ValidatesConfig(...)].
     */
    private function rewriteAttribute(Attribute $node): ?Attribute
    {
        $name = $node->name->toString();

        if ($name !== 'StarterValidator' && ! str_ends_with($name, '\\StarterValidator')) {
            return null;
        }

        // Replace just the name, keeping args intact
        $newName = str_ends_with($name, '\\StarterValidator')
            ? str_replace('\\StarterValidator', '\\ValidatesConfig', $name)
            : 'ValidatesConfig';

        $node->name = new Name($newName, $node->name->getAttributes());

        $this->recordChange($node->getStartLine(), '#[StarterValidator] → #[ValidatesConfig]');

        return $node;
    }

    private function recordChange(int $line, string $description): void
    {
        $this->changeCount++;
        $this->changes[] = [$this->filePath, $line, $description];
    }

    /**
     * Extract the short (unqualified) class name from a FQCN string.
     */
    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}
