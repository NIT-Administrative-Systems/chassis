<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps;

use Illuminate\Support\Facades\File;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Concerns\TracksChanges;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Contracts\MigrationStep;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationContext;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Phase 4a: Generate app-specific subclass files that extend chassis base classes.
 *
 * Scaffolds EnvironmentLockdown, SentryExceptionHandler, AuthenticatesAccessTokens,
 * and LogsApiRequests from .stub templates or dynamic generation.
 *
 * For EnvironmentLockdown, AuthenticatesAccessTokens, and LogsApiRequests, the
 * original files are parsed (before deletion) to extract app-specific values so
 * the generated stubs match the app's existing behavior.
 */
class ScaffoldSubclassesStep implements MigrationStep
{
    use TracksChanges;

    /**
     * Subclasses to scaffold: relative path => stub filename.
     *
     * @var array<string, string>
     */
    private const array SCAFFOLDS = [
        'app/Http/Middleware/EnvironmentLockdown.php' => 'environment-lockdown.stub',
        'app/Domains/Core/Exceptions/SentryExceptionHandler.php' => 'sentry-exception-handler.stub',
    ];

    private const string LOCKDOWN_PATH = 'app/Http/Middleware/EnvironmentLockdown.php';

    /**
     * Candidate paths for the auth middleware (checked in order).
     *
     * @var list<string>
     */
    private const array AUTH_MIDDLEWARE_PATHS = [
        'app/Domains/Auth/Http/Middleware/AuthenticatesAccessTokens.php',
        'app/Http/Middleware/AuthenticatesApiTokens.php',
        'app/Http/Middleware/AuthenticatesAccessTokens.php',
    ];

    /**
     * Candidate paths for the logs middleware (checked in order).
     *
     * @var list<string>
     */
    private const array LOGS_MIDDLEWARE_PATHS = [
        'app/Domains/Auth/Http/Middleware/LogsApiRequests.php',
        'app/Http/Middleware/LogsApiRequests.php',
    ];

    /**
     * Parsed values from the original EnvironmentLockdown file.
     *
     * Captured at construction time (before the delete step runs).
     *
     * @var array{configKey: string, exemptedRoutes: list<string>|null, redirectRoute: string}
     */
    private readonly array $lockdownConfig;

    /**
     * Parsed values from the original AuthenticatesAccessTokens / AuthenticatesApiTokens file.
     *
     * Null when the file was not found.
     *
     * @var array{path: string, namespace: string, className: string, tokenModelFqcn: string, tokenModelShort: string, authTypeValue: string, authTypeFqcn: string|null, missingIpException: string, missingIpExceptionFqcn: string|null}|null
     */
    private readonly ?array $authMiddlewareConfig;

    /**
     * Parsed values from the original LogsApiRequests file.
     *
     * Null when the file was not found.
     *
     * @var array{path: string, namespace: string, className: string, logModelFqcn: string, logModelShort: string, configPrefix: string, tokenColumn: string, persistedColumns: list<string>|null}|null
     */
    private readonly ?array $logsMiddlewareConfig;

    public function __construct()
    {
        $this->lockdownConfig = $this->parseOriginalEnvironmentLockdown();
        $this->authMiddlewareConfig = $this->parseOriginalAuthMiddleware();
        $this->logsMiddlewareConfig = $this->parseOriginalLogsMiddleware();
    }

    public function label(): string
    {
        return 'Generating app-specific subclasses...';
    }

    public function run(MigrationContext $context): void
    {
        $context->command->newLine();
        $context->command->getOutput()->writeln('<info>' . $this->label() . '</info>');
        $context->command->newLine();

        foreach (self::SCAFFOLDS as $relativePath => $stubFile) {
            $this->scaffoldFile($relativePath, $stubFile, $context);
        }

        $this->scaffoldAuthMiddleware($context);
        $this->scaffoldLogsMiddleware($context);
    }

    private function scaffoldFile(string $relativePath, string $stubFile, MigrationContext $context): void
    {
        if (File::exists(base_path($relativePath))) {
            $context->command->line("  <fg=yellow>⊘</> {$relativePath} (already exists, skipped)");

            return;
        }

        $content = $relativePath === self::LOCKDOWN_PATH
            ? $this->generateLockdownStub()
            : File::get(__DIR__ . '/../Stubs/' . $stubFile);

        if (! $context->isDryRun) {
            File::ensureDirectoryExists(dirname(base_path($relativePath)));
            File::put(base_path($relativePath), $content);
        }

        $this->incrementCounter($context, 'filesScaffolded');
        $context->command->line("  <fg=green>✓</> {$relativePath} (extends chassis base)");
    }

    /**
     * Parse the original EnvironmentLockdown.php to extract config key,
     * exempted routes, and redirect route.
     *
     * Called at construction time so the file is read before the delete step.
     *
     * @return array{configKey: string, exemptedRoutes: list<string>|null, redirectRoute: string}
     */
    private function parseOriginalEnvironmentLockdown(): array
    {
        $defaults = [
            'configKey' => 'platform.lockdown.enabled',
            'exemptedRoutes' => null,
            'redirectRoute' => 'platform.environment-lockdown',
        ];

        $path = base_path(self::LOCKDOWN_PATH);

        if (! File::exists($path)) {
            return $defaults;
        }

        $code = File::get($path);

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);

        if ($stmts === null) {
            return $defaults;
        }

        $finder = new NodeFinder();

        return [
            'configKey' => $this->extractConfigKey($finder, $stmts) ?? $defaults['configKey'],
            'exemptedRoutes' => $this->extractExemptedRoutes($finder, $stmts),
            'redirectRoute' => $this->extractRedirectRoute($finder, $stmts) ?? $defaults['redirectRoute'],
        ];
    }

    /**
     * Find config('some.key') or config('some.key', ...) inside a negation and extract the key.
     *
     * Looks for patterns like `! config('platform.lockdown')` or `config('platform.lockdown.enabled')`.
     *
     * @param  array<Node\Stmt>  $stmts
     */
    private function extractConfigKey(NodeFinder $finder, array $stmts): ?string
    {
        /** @var list<FuncCall> $configCalls */
        $configCalls = $finder->find($stmts, function (Node $node): bool {
            return $node instanceof FuncCall
                && $node->name instanceof Node\Name
                && $node->name->toString() === 'config'
                && isset($node->args[0])
                && $node->args[0] instanceof Node\Arg
                && $node->args[0]->value instanceof String_;
        });

        foreach ($configCalls as $call) {
            /** @var Node\Arg $firstArg */
            $firstArg = $call->args[0];
            /** @var String_ $stringNode */
            $stringNode = $firstArg->value;
            $key = $stringNode->value;

            // Match lockdown-related config keys
            if (str_contains($key, 'lockdown')) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Extract exempted routes from either config() call or hardcoded routeIs() array.
     *
     * @param  array<Node\Stmt>  $stmts
     * @return list<string>|null Null means use config()-based approach; list means hardcoded routes.
     */
    private function extractExemptedRoutes(NodeFinder $finder, array $stmts): ?array
    {
        // First check for config('...exempted_routes...') pattern (newer)
        /** @var list<FuncCall> $configCalls */
        $configCalls = $finder->find($stmts, function (Node $node): bool {
            return $node instanceof FuncCall
                && $node->name instanceof Node\Name
                && $node->name->toString() === 'config'
                && isset($node->args[0])
                && $node->args[0] instanceof Node\Arg
                && $node->args[0]->value instanceof String_;
        });

        foreach ($configCalls as $call) {
            /** @var Node\Arg $firstArg */
            $firstArg = $call->args[0];
            /** @var String_ $stringNode */
            $stringNode = $firstArg->value;

            if (str_contains($stringNode->value, 'exempted_routes')) {
                // Uses config-based approach — no hardcoded routes needed
                return null;
            }
        }

        // Look for $request->routeIs([...]) in an isExemptedRoute method
        /** @var list<MethodCall> $routeIsCalls */
        $routeIsCalls = $finder->find($stmts, function (Node $node): bool {
            return $node instanceof MethodCall
                && $node->name instanceof Node\Identifier
                && $node->name->name === 'routeIs';
        });

        foreach ($routeIsCalls as $call) {
            if (! isset($call->args[0])) {
                continue;
            }

            $firstArg = $call->args[0];

            if (! $firstArg instanceof Node\Arg) {
                continue;
            }

            if ($firstArg->value instanceof Array_) {
                return $this->extractStringArrayItems($firstArg->value);
            }
        }

        return null;
    }

    /**
     * Extract the redirect route name from redirect(route('...')) or redirect()->route('...').
     *
     * @param  array<Node\Stmt>  $stmts
     */
    private function extractRedirectRoute(NodeFinder $finder, array $stmts): ?string
    {
        // Pattern 1: redirect(route('name'))
        /** @var list<FuncCall> $routeCalls */
        $routeCalls = $finder->find($stmts, function (Node $node): bool {
            return $node instanceof FuncCall
                && $node->name instanceof Node\Name
                && $node->name->toString() === 'route'
                && isset($node->args[0])
                && $node->args[0] instanceof Node\Arg
                && $node->args[0]->value instanceof String_;
        });

        foreach ($routeCalls as $call) {
            /** @var Node\Arg $firstArg */
            $firstArg = $call->args[0];
            /** @var String_ $stringNode */
            $stringNode = $firstArg->value;
            $routeName = $stringNode->value;

            // Only match routes that look like lockdown/redirect routes
            if (str_contains($routeName, 'lockdown') || str_contains($routeName, 'lock')) {
                return $routeName;
            }
        }

        // Pattern 2: redirect()->route('name') — look for route() as a method call
        /** @var list<MethodCall> $methodRouteCalls */
        $methodRouteCalls = $finder->find($stmts, function (Node $node): bool {
            return $node instanceof MethodCall
                && $node->name instanceof Node\Identifier
                && $node->name->name === 'route'
                && isset($node->args[0])
                && $node->args[0] instanceof Node\Arg
                && $node->args[0]->value instanceof String_;
        });

        foreach ($methodRouteCalls as $call) {
            /** @var Node\Arg $firstArg */
            $firstArg = $call->args[0];
            /** @var String_ $stringNode */
            $stringNode = $firstArg->value;
            $routeName = $stringNode->value;

            if (str_contains($routeName, 'lockdown') || str_contains($routeName, 'lock')) {
                return $routeName;
            }
        }

        return null;
    }

    /**
     * Extract string literal values from an Array_ node.
     *
     * @return list<string>
     */
    private function extractStringArrayItems(Array_ $array): array
    {
        $items = [];

        foreach ($array->items as $item) {
            if ($item !== null && $item->value instanceof String_) {
                $items[] = $item->value->value;
            }
        }

        return $items;
    }

    /**
     * Generate the EnvironmentLockdown stub dynamically based on parsed config.
     */
    private function generateLockdownStub(): string
    {
        $configKey = $this->lockdownConfig['configKey'];
        $exemptedRoutes = $this->lockdownConfig['exemptedRoutes'];
        $redirectRoute = $this->lockdownConfig['redirectRoute'];

        $exemptedRoutesMethod = $exemptedRoutes !== null
            ? $this->buildHardcodedExemptedRoutesMethod($exemptedRoutes)
            : $this->buildConfigExemptedRoutesMethod();

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\Http\Middleware;

            use Illuminate\Http\Request;
            use Northwestern\SysDev\Chassis\Http\Middleware\EnvironmentLockdown as BaseEnvironmentLockdown;

            /**
             * Restricts application access to users with assigned roles beyond the default Northwestern User role.
             *
             * This middleware is typically enabled in non-production environments (staging, demo)
             * to prevent unauthorized users who discover the application URL from accessing it.
             * Users with only the default Northwestern User role (or no roles) are redirected
             * to a lockdown page explaining they need to be granted access by an administrator.
             *
             * ## Exemptions
             *
             * The following requests bypass lockdown:
             * - Lockdown disabled via config
             * - User is impersonating another user
             * - User has at least one role besides Northwestern User
             * - Request is to an authentication or lockdown route
             */
            class EnvironmentLockdown extends BaseEnvironmentLockdown
            {
                protected function isEnabled(): bool
                {
                    return (bool) config('{$configKey}');
                }

                protected function isAuthorized(Request \$request): bool
                {
                    return \$request->user()->isImpersonated()
                        || \$request->user()->non_default_roles->isNotEmpty();
                }

                protected function redirectRoute(): string
                {
                    return '{$redirectRoute}';
                }

            {$exemptedRoutesMethod}
            }

            PHP;
    }

    /**
     * Build the exemptedRoutePatterns() method body with hardcoded route names.
     *
     * @param  list<string>  $routes
     */
    private function buildHardcodedExemptedRoutesMethod(array $routes): string
    {
        if ($routes === []) {
            return <<<'PHP'
                    protected function exemptedRoutePatterns(): array
                    {
                        return [];
                    }
                PHP;
        }

        $routeLines = array_map(
            fn (string $route): string => "            '{$route}',",
            $routes,
        );

        $routeList = implode("\n", $routeLines);

        return <<<PHP
                protected function exemptedRoutePatterns(): array
                {
                    return [
            {$routeList}
                    ];
                }
            PHP;
    }

    private function buildConfigExemptedRoutesMethod(): string
    {
        return <<<'PHP'
                protected function exemptedRoutePatterns(): array
                {
                    return config('platform.lockdown.exempted_routes', []);
                }
            PHP;
    }

    // -----------------------------------------------------------------------
    //  Auth middleware scaffolding
    // -----------------------------------------------------------------------

    /**
     * Find and parse the original auth middleware file.
     *
     * @return array{path: string, namespace: string, className: string, tokenModelFqcn: string, tokenModelShort: string, authTypeValue: string, authTypeFqcn: string|null, missingIpException: string, missingIpExceptionFqcn: string|null}|null
     */
    private function parseOriginalAuthMiddleware(): ?array
    {
        [$relativePath, , $stmts] = $this->findAndParseFile(self::AUTH_MIDDLEWARE_PATHS);

        if ($relativePath === null || $stmts === null) {
            return null;
        }

        $finder = new NodeFinder();

        $namespace = $this->extractNamespace($stmts);
        $className = $this->extractClassName($finder, $stmts);
        $imports = $this->extractUseStatements($stmts);

        if ($namespace === null || $className === null) {
            return null;
        }

        $tokenModelFqcn = $this->findTokenModelImport($imports);
        $tokenModelShort = $this->shortClassName($tokenModelFqcn);
        $authTypeValue = $this->extractAuthTypeValue($finder, $stmts);
        $authTypeFqcn = $this->resolveAuthTypeFqcn($authTypeValue, $imports);
        $missingIpException = $this->extractMissingIpException($finder, $stmts);
        $missingIpExceptionFqcn = $this->resolveImportByShortName($missingIpException, $imports);

        return [
            'path' => $relativePath,
            'namespace' => $namespace,
            'className' => $className,
            'tokenModelFqcn' => $tokenModelFqcn,
            'tokenModelShort' => $tokenModelShort,
            'authTypeValue' => $authTypeValue,
            'authTypeFqcn' => $authTypeFqcn,
            'missingIpException' => $missingIpException,
            'missingIpExceptionFqcn' => $missingIpExceptionFqcn,
        ];
    }

    /**
     * Scaffold the auth middleware subclass at its original path.
     */
    private function scaffoldAuthMiddleware(MigrationContext $context): void
    {
        if ($this->authMiddlewareConfig === null) {
            return;
        }

        $relativePath = $this->authMiddlewareConfig['path'];

        if (File::exists(base_path($relativePath))) {
            $context->command->line("  <fg=yellow>⊘</> {$relativePath} (already exists, skipped)");

            return;
        }

        $content = $this->generateAuthMiddlewareStub();

        if (! $context->isDryRun) {
            File::ensureDirectoryExists(dirname(base_path($relativePath)));
            File::put(base_path($relativePath), $content);
        }

        $this->incrementCounter($context, 'filesScaffolded');
        $context->command->line("  <fg=green>✓</> {$relativePath} (extends chassis base)");
    }

    /**
     * Generate the auth middleware stub from parsed config.
     */
    private function generateAuthMiddlewareStub(): string
    {
        $c = $this->authMiddlewareConfig;

        assert($c !== null);

        $namespace = $c['namespace'];
        $className = $c['className'];
        $tokenModelFqcn = $c['tokenModelFqcn'];
        $tokenModelShort = $c['tokenModelShort'];
        $authTypeValue = $c['authTypeValue'];
        // Build the use statements
        $useStatements = "use {$tokenModelFqcn};";

        if ($c['authTypeFqcn'] !== null) {
            $useStatements .= "\nuse {$c['authTypeFqcn']};";
        }

        $useStatements .= "\nuse Northwestern\\SysDev\\Chassis\\Contracts\\AccessTokenContract;";
        $useStatements .= "\nuse Northwestern\\SysDev\\Chassis\\Exceptions\\MissingRequestIpForRestrictedTokenException;";
        $useStatements .= "\nuse Northwestern\\SysDev\\Chassis\\Http\\Middleware\\AuthenticatesAccessTokens as BaseAuthenticatesAccessTokens;";

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            {$useStatements}

            class {$className} extends BaseAuthenticatesAccessTokens
            {
                protected function findActiveToken(string \$tokenHash): ?AccessTokenContract
                {
                    return {$tokenModelShort}::query()
                        ->withWhereHas('user', fn (\$query) => \$query->where('auth_type', {$authTypeValue}))
                        ->where('token_hash', \$tokenHash)
                        ->active()
                        ->first();
                }

                protected function hashToken(#[\SensitiveParameter] string \$plainToken): string
                {
                    return {$tokenModelShort}::hashFromPlain(\$plainToken);
                }

                protected function reportMissingIp(array \$allowedIps): void
                {
                    report(new MissingRequestIpForRestrictedTokenException(array_values(\$allowedIps)));
                }
            }

            PHP;
    }

    // -----------------------------------------------------------------------
    //  Logs middleware scaffolding
    // -----------------------------------------------------------------------

    /**
     * Find and parse the original logs middleware file.
     *
     * @return array{path: string, namespace: string, className: string, logModelFqcn: string, logModelShort: string, configPrefix: string, tokenColumn: string, persistedColumns: list<string>|null}|null
     */
    private function parseOriginalLogsMiddleware(): ?array
    {
        [$relativePath, $code, $stmts] = $this->findAndParseFile(self::LOGS_MIDDLEWARE_PATHS);

        if ($relativePath === null || $stmts === null) {
            return null;
        }

        $finder = new NodeFinder();

        $namespace = $this->extractNamespace($stmts);
        $className = $this->extractClassName($finder, $stmts);
        $imports = $this->extractUseStatements($stmts);

        if ($namespace === null || $className === null) {
            return null;
        }

        $logModelFqcn = $this->findLogModelImport($imports);
        $logModelShort = $this->shortClassName($logModelFqcn);
        $configPrefix = $this->extractLogsConfigPrefix($finder, $stmts);
        $tokenColumn = $this->extractTokenColumn($code);
        $persistedColumns = $this->extractPersistedColumns($finder, $stmts);

        return [
            'path' => $relativePath,
            'namespace' => $namespace,
            'className' => $className,
            'logModelFqcn' => $logModelFqcn,
            'logModelShort' => $logModelShort,
            'configPrefix' => $configPrefix,
            'tokenColumn' => $tokenColumn,
            'persistedColumns' => $persistedColumns,
        ];
    }

    /**
     * Extract the column list from the original middleware's `::create([...])`
     * or `->insert([...])` call, so the scaffolded persistLog() only touches
     * columns the app's model/table actually has.
     *
     * Scoped to calls whose key array includes 'trace_id' — the canonical
     * marker that this is the API log insert and not some other array.
     *
     * @param  array<Node\Stmt>  $stmts
     * @return list<string>|null List of app-side column names, or null if not found.
     */
    private function extractPersistedColumns(NodeFinder $finder, array $stmts): ?array
    {
        $candidates = $finder->find($stmts, function (Node $node): bool {
            if (! $node instanceof Node\Expr\StaticCall && ! $node instanceof MethodCall) {
                return false;
            }

            if (! $node->name instanceof Node\Identifier) {
                return false;
            }

            if (! in_array($node->name->name, ['create', 'insert', 'forceCreate'], true)) {
                return false;
            }

            return isset($node->args[0])
                && $node->args[0] instanceof Node\Arg
                && $node->args[0]->value instanceof Array_;
        });

        foreach ($candidates as $call) {
            if (! $call instanceof Node\Expr\StaticCall && ! $call instanceof MethodCall) {
                continue;
            }

            $firstArg = $call->args[0] ?? null;

            if (! $firstArg instanceof Node\Arg) {
                continue;
            }

            $array = $firstArg->value;

            if (! $array instanceof Array_) {
                continue;
            }

            $keys = [];
            foreach ($array->items as $item) {
                if ($item !== null && $item->key instanceof String_) {
                    $keys[] = $item->key->value;
                }
            }

            if (in_array('trace_id', $keys, true)) {
                return $keys;
            }
        }

        return null;
    }

    /**
     * Scaffold the logs middleware subclass at its original path.
     */
    private function scaffoldLogsMiddleware(MigrationContext $context): void
    {
        if ($this->logsMiddlewareConfig === null) {
            return;
        }

        $relativePath = $this->logsMiddlewareConfig['path'];

        if (File::exists(base_path($relativePath))) {
            $context->command->line("  <fg=yellow>⊘</> {$relativePath} (already exists, skipped)");

            return;
        }

        $content = $this->generateLogsMiddlewareStub();

        if (! $context->isDryRun) {
            File::ensureDirectoryExists(dirname(base_path($relativePath)));
            File::put(base_path($relativePath), $content);
        }

        $this->incrementCounter($context, 'filesScaffolded');
        $context->command->line("  <fg=green>✓</> {$relativePath} (extends chassis base)");
    }

    /**
     * Generate the logs middleware stub from parsed config.
     */
    private function generateLogsMiddlewareStub(): string
    {
        $c = $this->logsMiddlewareConfig;

        assert($c !== null);

        $namespace = $c['namespace'];
        $className = $c['className'];
        $logModelFqcn = $c['logModelFqcn'];
        $configPrefix = $c['configPrefix'];
        $persistBody = $this->buildPersistLogBody($c['logModelShort'], $c['tokenColumn'], $c['persistedColumns']);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use {$logModelFqcn};
            use Northwestern\SysDev\Chassis\Http\Middleware\LogsApiRequests as BaseLogsApiRequests;

            class {$className} extends BaseLogsApiRequests
            {
                protected function isEnabled(): bool
                {
                    return (bool) config('{$configPrefix}.enabled');
                }

                protected function isSamplingEnabled(): bool
                {
                    return (bool) config('{$configPrefix}.sampling.enabled');
                }

                protected function sampleRate(): float
                {
                    return (float) config('{$configPrefix}.sampling.rate', 1.0);
                }

                protected function persistLog(array \$data): void
                {
            {$persistBody}
                }
            }

            PHP;
    }

    /**
     * Build the body of persistLog(): an explicit mapping of the app's columns
     * to the base class's standardized $data keys.
     *
     * When we can extract the original column list, we only populate columns
     * the app's table actually has — so additions to the base class's $data
     * (e.g. request_bytes) don't break apps whose schema predates them.
     *
     * Falls back to a generic token-rename pattern if the original couldn't
     * be parsed.
     *
     * @param  list<string>|null  $persistedColumns
     */
    private function buildPersistLogBody(string $logModelShort, string $tokenColumn, ?array $persistedColumns): string
    {
        if ($persistedColumns === null) {
            return <<<PHP
                        // Map standardized token_id to the app's column name
                        \$data['{$tokenColumn}'] = \$data['token_id'];
                        unset(\$data['token_id']);

                        {$logModelShort}::create(\$data);
                PHP;
        }

        $standardKeys = [
            'trace_id', 'user_id', 'token_id', 'method', 'path', 'route_name',
            'ip_address', 'status_code', 'duration_ms', 'request_bytes',
            'response_bytes', 'user_agent', 'failure_reason',
        ];

        $lines = [];
        foreach ($persistedColumns as $column) {
            $source = str_ends_with($column, 'token_id') ? 'token_id' : $column;

            if (! in_array($source, $standardKeys, true)) {
                continue;
            }

            $lines[] = "            '{$column}' => \$data['{$source}'],";
        }

        $arrayBody = implode("\n", $lines);

        return <<<PHP
                    {$logModelShort}::create([
            {$arrayBody}
                    ]);
            PHP;
    }

    // -----------------------------------------------------------------------
    //  Shared AST helpers
    // -----------------------------------------------------------------------

    /**
     * Try each candidate path, return the first that exists and parses.
     *
     * @param  list<string>  $candidates  Relative paths to try.
     * @return array{0: string|null, 1: string, 2: array<Node\Stmt>|null}
     */
    private function findAndParseFile(array $candidates): array
    {
        foreach ($candidates as $relativePath) {
            $absolutePath = base_path($relativePath);

            if (! File::exists($absolutePath)) {
                continue;
            }

            $code = File::get($absolutePath);
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $stmts = $parser->parse($code);

            return [$relativePath, $code, $stmts];
        }

        return [null, '', null];
    }

    /**
     * Extract the namespace string from parsed statements.
     *
     * @param  array<Node\Stmt>  $stmts
     */
    private function extractNamespace(array $stmts): ?string
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_ && $stmt->name instanceof Node\Name) {
                return $stmt->name->toString();
            }
        }

        return null;
    }

    /**
     * Extract the first class name from parsed statements.
     *
     * @param  array<Node\Stmt>  $stmts
     */
    private function extractClassName(NodeFinder $finder, array $stmts): ?string
    {
        /** @var Class_|null $class */
        $class = $finder->findFirstInstanceOf($stmts, Class_::class);

        return $class?->name?->name;
    }

    /**
     * Collect all `use` import FQCNs from the file.
     *
     * @param  array<Node\Stmt>  $stmts
     * @return list<string>
     */
    private function extractUseStatements(array $stmts): array
    {
        $imports = [];

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_) {
                foreach ($stmt->stmts as $inner) {
                    if ($inner instanceof Use_) {
                        foreach ($inner->uses as $use) {
                            $imports[] = $use->name->toString();
                        }
                    }
                }
            } elseif ($stmt instanceof Use_) {
                foreach ($stmt->uses as $use) {
                    $imports[] = $use->name->toString();
                }
            }
        }

        return $imports;
    }

    /**
     * Get the short (unqualified) class name from an FQCN.
     */
    private function shortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }

    /**
     * Resolve a short class name to its FQCN using the file's import list.
     *
     * @param  list<string>  $imports
     */
    private function resolveImportByShortName(string $shortName, array $imports): ?string
    {
        if ($shortName === '') {
            return null;
        }

        foreach ($imports as $import) {
            if ($this->shortClassName($import) === $shortName) {
                return $import;
            }
        }

        return null;
    }

    // -----------------------------------------------------------------------
    //  Auth middleware AST extraction
    // -----------------------------------------------------------------------

    /**
     * Find the token model import (a use statement containing "Token" in a Models namespace).
     *
     * Excludes exceptions, enums, contexts, and failure-related classes.
     *
     * @param  list<string>  $imports
     */
    private function findTokenModelImport(array $imports): string
    {
        foreach ($imports as $import) {
            $short = $this->shortClassName($import);

            if (str_contains($short, 'Token')
                && str_contains($import, 'Models')
                && ! str_contains($short, 'Context')
                && ! str_contains($short, 'Failure')) {
                return $import;
            }
        }

        return 'App\\Models\\AccessToken';
    }

    /**
     * Extract the auth type enum value from `where('auth_type', ...)`.
     *
     * @param  array<Node\Stmt>  $stmts
     */
    private function extractAuthTypeValue(NodeFinder $finder, array $stmts): string
    {
        /** @var list<MethodCall> $whereCalls */
        $whereCalls = $finder->find($stmts, function (Node $node): bool {
            return $node instanceof MethodCall
                && $node->name instanceof Node\Identifier
                && $node->name->name === 'where'
                && isset($node->args[0], $node->args[1])
                && $node->args[0] instanceof Node\Arg
                && $node->args[0]->value instanceof String_
                && $node->args[0]->value->value === 'auth_type';
        });

        foreach ($whereCalls as $call) {
            /** @var Node\Arg $secondArg */
            $secondArg = $call->args[1];

            if (! $secondArg instanceof Node\Arg) {
                continue;
            }

            $value = $secondArg->value;

            // Handle Enum::Case or Class::CONSTANT patterns
            if ($value instanceof Node\Expr\ClassConstFetch
                && $value->class instanceof Node\Name
                && $value->name instanceof Node\Identifier) {
                $enumClass = $value->class->toString();
                $enumCase = $value->name->name;

                return "{$enumClass}::{$enumCase}";
            }

            // Handle string values
            if ($value instanceof String_) {
                return "'{$value->value}'";
            }
        }

        return 'AuthType::API';
    }

    /**
     * Resolve the FQCN of the auth type enum/class from the parsed auth type value.
     *
     * Called during initial parsing so the imports are still available.
     *
     * @param  list<string>  $imports
     */
    private function resolveAuthTypeFqcn(string $authTypeValue, array $imports): ?string
    {
        // If it starts with a quote, it's a string literal — no import needed
        if (str_starts_with($authTypeValue, "'")) {
            return null;
        }

        // Extract the class name part (before ::)
        $parts = explode('::', $authTypeValue);

        if (count($parts) !== 2) {
            return null;
        }

        $shortClass = $parts[0];

        foreach ($imports as $import) {
            if ($this->shortClassName($import) === $shortClass) {
                return $import;
            }
        }

        return null;
    }

    /**
     * Extract the exception class name from `report(new XxxException(...))`.
     *
     * @param  array<Node\Stmt>  $stmts
     */
    private function extractMissingIpException(NodeFinder $finder, array $stmts): string
    {
        /** @var list<New_> $newExprs */
        $newExprs = $finder->find($stmts, function (Node $node): bool {
            if (! $node instanceof New_) {
                return false;
            }

            // Check that this New_ is inside a report() call
            return $node->class instanceof Node\Name;
        });

        foreach ($newExprs as $newExpr) {
            /** @var Node\Name $className */
            $className = $newExpr->class;
            $name = $className->toString();

            if (str_contains($name, 'Missing') || str_contains($name, 'Ip') || str_contains($name, 'Restricted')) {
                return $name;
            }
        }

        return '';
    }

    // -----------------------------------------------------------------------
    //  Logs middleware AST extraction
    // -----------------------------------------------------------------------

    /**
     * Find the log model import (a use statement containing "Log" or "RequestLog").
     *
     * @param  list<string>  $imports
     */
    private function findLogModelImport(array $imports): string
    {
        foreach ($imports as $import) {
            $short = $this->shortClassName($import);

            if ((str_contains($short, 'Log') || str_contains($short, 'RequestLog'))
                && ! str_contains($short, 'Context')
                && ! str_contains($short, 'Lottery')
                && ! str_contains($short, 'Failure')) {
                return $import;
            }
        }

        return 'App\\Models\\ApiRequestLog';
    }

    /**
     * Extract the common config prefix from config() calls (e.g. "api.request_logging").
     *
     * @param  array<Node\Stmt>  $stmts
     */
    private function extractLogsConfigPrefix(NodeFinder $finder, array $stmts): string
    {
        /** @var list<FuncCall> $configCalls */
        $configCalls = $finder->find($stmts, function (Node $node): bool {
            return $node instanceof FuncCall
                && $node->name instanceof Node\Name
                && $node->name->toString() === 'config'
                && isset($node->args[0])
                && $node->args[0] instanceof Node\Arg
                && $node->args[0]->value instanceof String_;
        });

        $keys = [];
        foreach ($configCalls as $call) {
            /** @var Node\Arg $firstArg */
            $firstArg = $call->args[0];
            /** @var String_ $stringNode */
            $stringNode = $firstArg->value;
            $keys[] = $stringNode->value;
        }

        if ($keys === []) {
            return 'api.request_logging';
        }

        // Find common prefix by trimming the last segment from each key
        // e.g. "api.request_logging.enabled" → "api.request_logging"
        // e.g. "api.request_logging.sampling.enabled" → "api.request_logging.sampling"
        // We want the shortest prefix that's shared.
        $prefixes = array_map(function (string $key): string {
            // Remove .enabled, .rate, etc. to get the logging prefix
            // Strip .sampling.enabled, .sampling.rate, .enabled
            $key = preg_replace('/\.sampling\.(enabled|rate)$/', '', $key) ?? $key;

            return preg_replace('/\.enabled$/', '', $key) ?? $key;
        }, $keys);

        // Return the shortest common prefix
        $prefixes = array_unique($prefixes);

        if (count($prefixes) === 1) {
            return $prefixes[array_key_first($prefixes)];
        }

        // Multiple prefixes — find the shortest
        usort($prefixes, fn (string $a, string $b): int => strlen($a) <=> strlen($b));

        return $prefixes[0];
    }

    /**
     * Extract the token column name from the create() call (look for the key that maps TOKEN_ID).
     *
     * Matches patterns like `'access_token_id' => Context::get(...)` or `'user_api_token_id' => ...`.
     */
    private function extractTokenColumn(string $code): string
    {
        if (preg_match("/'(\\w*token_id)'\s*=>/", $code, $matches)) {
            return $matches[1];
        }

        return 'access_token_id';
    }
}
