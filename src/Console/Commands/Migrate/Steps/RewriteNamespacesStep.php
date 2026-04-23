<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps;

use Illuminate\Support\Facades\File;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Concerns\InteractsWithAst;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationContext;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationManifest;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Transformers\ChassisMigrationVisitor;
use Northwestern\SysDev\Chassis\Rector\ChassisNamespaceRector;
use PhpParser\Node;
use PhpParser\NodeTraverser;
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
class RewriteNamespacesStep extends AbstractMigrationStep
{
    use InteractsWithAst;

    /** @var non-empty-list<string> */
    private const array SEARCH_DIRECTORIES = [
        'app',
        'bootstrap',
        'config',
        'database',
        'routes',
        'tests',
    ];

    public function label(): string
    {
        return 'Scanning for namespace references...';
    }

    public function run(MigrationContext $context): void
    {
        $this->writeStepHeading($context, $this->label());

        $directories = array_values(array_filter(
            array_map(static fn (string $relativePath): string => base_path($relativePath), self::SEARCH_DIRECTORIES),
            'is_dir',
        ));

        if ($directories === []) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($directories)->name('*.php');

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $printer = new Standard();

        $affectedFiles = [];

        foreach ($finder as $file) {
            $rewriteCount = $this->rewriteFileNamespaces($file, $parser, $printer, $context);
            if ($rewriteCount > 0) {
                $affectedFiles[] = $this->toRelativePath($file->getRealPath());
                $this->incrementCounter($context, 'namespacesRewritten', $rewriteCount);
            }
        }

        $this->markFileModified($context, count($affectedFiles));

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
    private function rewriteFileNamespaces(SplFileInfo $file, Parser $parser, Standard $printer, MigrationContext $context): int
    {
        $code = File::get($file->getRealPath());
        $oldStmts = $parser->parse($code);

        if ($oldStmts === null) {
            return 0;
        }

        // First pass: clone the AST so we can diff for format-preserving output
        $newStmts = $this->cloneStatementTree($oldStmts);

        $renames = array_diff_key(
            ChassisNamespaceRector::CLASS_RENAMES,
            array_flip(MigrationManifest::EXCLUDED_FROM_REWRITE),
        );

        // Second pass: apply all transformations
        $visitor = new ChassisMigrationVisitor(
            $renames,
            $this->toRelativePath($file->getRealPath()),
        );

        $transformTraverser = new NodeTraverser();
        $transformTraverser->addVisitor($visitor);
        /** @var list<Node\Stmt> $transformedStmts */
        $transformedStmts = $transformTraverser->traverse($newStmts);

        // Add pending use statements from same-namespace resolution fixes
        foreach ($visitor->getPendingUseStatements() as $fqcn) {
            $this->ensureClassImport($transformedStmts, $fqcn);
        }

        $changeCount = $visitor->getChangeCount();

        if ($changeCount === 0) {
            return 0;
        }

        // Also rewrite PHPDoc type-hints in the raw source
        $newCode = $this->printWithOriginalFormatting($printer, $parser, $transformedStmts, $oldStmts);
        $newCode = $this->rewritePhpDocClassNames($newCode);

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
    private function rewritePhpDocClassNames(string $code): string
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
}
