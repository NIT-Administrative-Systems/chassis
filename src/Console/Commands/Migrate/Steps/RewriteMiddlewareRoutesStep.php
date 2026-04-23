<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps;

use Illuminate\Support\Facades\File;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationContext;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Rewrite EnsureFeatureEnabled::class references in route files to include
 * the feature-flag config key as a middleware parameter, e.g.
 * `EnsureFeatureEnabled::class . ':api.enabled'`.
 *
 * The config key is extracted from the consuming app's original
 * EnsureApiEnabled middleware (before deletion) so apps that store the flag
 * outside `api.enabled` (e.g. IHUB stores it in auth.php) get the right key.
 */
class RewriteMiddlewareRoutesStep extends AbstractMigrationStep
{
    /**
     * Candidate paths for the original feature-flag middleware (checked in order).
     *
     * @var list<string>
     */
    private const array MIDDLEWARE_PATHS = [
        'app/Http/Middleware/EnsureApiEnabled.php',
        'app/Http/Middleware/EnsureFeatureEnabled.php',
    ];

    private const string DEFAULT_CONFIG_KEY = 'api.enabled';

    /**
     * Config key extracted from the consuming app's middleware, captured at
     * construction time so the lookup still works after the delete step runs.
     */
    private readonly string $configKey;

    public function __construct()
    {
        $this->configKey = $this->detectOriginalMiddlewareConfigKey() ?? self::DEFAULT_CONFIG_KEY;
    }

    public function label(): string
    {
        return 'Rewriting EnsureFeatureEnabled middleware in routes...';
    }

    public function run(MigrationContext $context): void
    {
        $routeFiles = glob(base_path('routes/*.php')) ?: [];
        $modifiedCount = 0;
        $replacement = "EnsureFeatureEnabled::class . ':{$this->configKey}'";

        foreach ($routeFiles as $routeFile) {
            $code = File::get($routeFile);

            if (! str_contains($code, 'EnsureFeatureEnabled::class')) {
                continue;
            }

            if (str_contains($code, "EnsureFeatureEnabled::class . ':")) {
                continue;
            }

            $newCode = str_replace(
                'EnsureFeatureEnabled::class',
                $replacement,
                $code,
            );

            if ($newCode === $code) {
                continue;
            }

            if (! $context->isDryRun) {
                File::put($routeFile, $newCode);
            }
            $modifiedCount++;
            $this->success($context, $this->toRelativePath($routeFile) . " (added :{$this->configKey} middleware parameter)");
        }

        if ($modifiedCount === 0) {
            return;
        }

        $this->markFileModified($context, $modifiedCount);
    }

    /**
     * Parse the consuming app's original feature-flag middleware to extract
     * the config key it checks.
     */
    private function detectOriginalMiddlewareConfigKey(): ?string
    {
        foreach (self::MIDDLEWARE_PATHS as $relativePath) {
            $absolutePath = base_path($relativePath);

            if (! File::exists($absolutePath)) {
                continue;
            }

            $code = File::get($absolutePath);
            $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);

            if ($stmts === null) {
                continue;
            }

            /** @var list<FuncCall> $configCalls */
            $configCalls = (new NodeFinder())->find($stmts, function (Node $node): bool {
                return $node instanceof FuncCall
                    && $node->name instanceof Node\Name
                    && $node->name->toString() === 'config'
                    && isset($node->args[0])
                    && $node->args[0] instanceof Node\Arg
                    && $node->args[0]->value instanceof String_;
            });

            foreach ($configCalls as $call) {
                $firstArg = $call->args[0];

                if (! $firstArg instanceof Node\Arg) {
                    continue;
                }

                $value = $firstArg->value;

                if ($value instanceof String_ && $value->value !== '') {
                    return $value->value;
                }
            }
        }

        return null;
    }
}
