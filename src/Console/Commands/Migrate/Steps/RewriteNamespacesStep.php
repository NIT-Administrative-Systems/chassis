<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps;

use Illuminate\Support\Facades\File;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Concerns\TracksChanges;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Contracts\MigrationStep;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationContext;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationManifest;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Transformers\ChassisMigrationVisitor;
use Northwestern\SysDev\Chassis\Rector\ChassisNamespaceRector;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Namespace_;
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
 * Phase 1 & 2: AST-based namespace rewriting + method signature transforms.
 *
 * Handles use statements, class references, FQ names, PHPDoc types,
 * DateTimeFormatter::datetime() second arg transform, and
 * #[StarterValidator] → #[ValidatesConfig] attribute rename.
 */
class RewriteNamespacesStep implements MigrationStep
{
    use TracksChanges;

    public function label(): string
    {
        return 'Scanning for namespace references...';
    }

    public function run(MigrationContext $context): void
    {
        $context->command->getOutput()->writeln('<info>' . $this->label() . '</info>');

        $directories = array_filter([
            base_path('app'),
            base_path('bootstrap'),
            base_path('config'),
            base_path('database'),
            base_path('routes'),
            base_path('tests'),
        ], 'is_dir');

        if ($directories === []) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($directories)->name('*.php');

        $parser = new ParserFactory()->createForNewestSupportedVersion();
        $printer = new Standard();

        $affectedFiles = [];

        foreach ($finder as $file) {
            $changes = $this->transformFile($file, $parser, $printer, $context);
            if ($changes > 0) {
                $affectedFiles[] = $this->relativePath($file->getRealPath());
                $this->incrementCounter($context, 'namespacesRewritten', $changes);
            }
        }

        if ($affectedFiles !== []) {
            $context->command->newLine();
            foreach ($affectedFiles as $path) {
                $context->command->line("  <fg=green>✓</> {$path}");
            }
        }

        $context->command->newLine();
        $context->command->line("  <fg=gray>{$context->namespacesRewritten} namespace references " . ($context->isDryRun ? 'would be' : '') . ' rewritten across ' . count($affectedFiles) . ' files</>');
    }

    /**
     * Parse a single file, run AST visitors, and write back if changed.
     *
     * Uses format-preserving printing: the original tokens are preserved for
     * untouched nodes, so only the actually-modified parts change on disk.
     */
    private function transformFile(SplFileInfo $file, Parser $parser, Standard $printer, MigrationContext $context): int
    {
        $code = File::get($file->getRealPath());
        $oldStmts = $parser->parse($code);

        if ($oldStmts === null) {
            return 0;
        }

        // First pass: clone the AST so we can diff for format-preserving output
        $cloneTraverser = new NodeTraverser();
        $cloneTraverser->addVisitor(new CloningVisitor());
        /** @var list<Node\Stmt> $newStmts */
        $newStmts = $cloneTraverser->traverse($oldStmts);

        $renames = array_diff_key(
            ChassisNamespaceRector::CLASS_RENAMES,
            array_flip(MigrationManifest::EXCLUDED_FROM_REWRITE),
        );

        // Second pass: apply all transformations
        $visitor = new ChassisMigrationVisitor(
            $renames,
            $this->relativePath($file->getRealPath()),
        );

        $transformTraverser = new NodeTraverser();
        $transformTraverser->addVisitor($visitor);
        /** @var list<Node\Stmt> $transformedStmts */
        $transformedStmts = $transformTraverser->traverse($newStmts);

        // Add pending use statements from same-namespace resolution fixes
        foreach ($visitor->getPendingUseStatements() as $fqcn) {
            $this->addUseStatement($transformedStmts, $fqcn);
        }

        $changeCount = $visitor->getChangeCount();

        if ($changeCount === 0) {
            return 0;
        }

        // Also rewrite PHPDoc type-hints in the raw source
        $newCode = $printer->printFormatPreserving($transformedStmts, $oldStmts, $parser->getTokens());
        $newCode = $this->rewritePhpDocTypes($newCode);

        if (! $context->isDryRun) {
            File::put($file->getRealPath(), $newCode);
        }

        // Record changes for the report
        foreach ($visitor->getChanges() as $change) {
            $context->changeLog[] = $change;
        }

        return $changeCount;
    }

    /**
     * Rewrite class references inside PHPDoc annotations that the AST visitor
     * cannot reach (e.g. @param, @return, @var type-hints with old FQCNs).
     */
    private function rewritePhpDocTypes(string $code): string
    {
        foreach (ChassisNamespaceRector::CLASS_RENAMES as $old => $new) {
            // Match old class names in PHPDoc contexts: after @param, @return, @var,
            // inside class-string<...>, array<...>, Collection<...>, etc.
            $escaped = preg_quote($old, '/');
            $replaced = preg_replace(
                '/(?<=@param\s|@return\s|@var\s|class-string<|<)' . $escaped . '/',
                $new,
                $code,
            );

            if (is_string($replaced)) {
                $code = $replaced;
            }
        }

        return $code;
    }

    private function relativePath(string $fullPath): string
    {
        return str_replace(base_path() . '/', '', $fullPath);
    }

    /**
     * Add a use statement to the AST if not already present.
     *
     * @param  list<Node\Stmt>  $stmts
     */
    private function addUseStatement(array &$stmts, string $fqcn): void
    {
        // Find the target statement list (inside Namespace_ if present)
        $targetStmts = &$stmts;
        foreach ($stmts as &$stmt) {
            if ($stmt instanceof Namespace_) {
                $targetStmts = &$stmt->stmts;
                break;
            }
        }
        unset($stmt);

        // Check if already imported
        foreach ($targetStmts as $s) {
            if ($s instanceof Use_) {
                foreach ($s->uses as $use) {
                    if ($use->name->toString() === $fqcn) {
                        return;
                    }
                }
            }
        }

        // Find the last use statement and insert after it
        $lastUseIndex = -1;
        foreach ($targetStmts as $i => $s) {
            if ($s instanceof Use_) {
                $lastUseIndex = $i;
            }
        }

        $newUse = new Use_([new UseUse(new Name($fqcn))]);

        if ($lastUseIndex >= 0) {
            array_splice($targetStmts, $lastUseIndex + 1, 0, [$newUse]);
        } else {
            array_splice($targetStmts, 1, 0, [$newUse]);
        }
    }
}
