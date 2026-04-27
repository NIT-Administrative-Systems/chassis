<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps;

use Illuminate\Support\Facades\File;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationContext;

/**
 * Upgrade the app's ApiToken model to implement chassis's access-token contract.
 */
class UpgradeApiTokenModelStep extends AbstractMigrationStep
{
    /** @var list<string> */
    private const array TARGET_FILES = [
        'app/Domains/Auth/Models/AccessToken.php',
        'app/Domains/User/Models/ApiToken.php',
    ];

    public function label(): string
    {
        return 'Upgrading access-token model...';
    }

    public function run(MigrationContext $context): void
    {
        foreach (self::TARGET_FILES as $relativePath) {
            $absolutePath = base_path($relativePath);

            if (! File::exists($absolutePath)) {
                continue;
            }

            $source = File::get($absolutePath);

            if (str_contains($source, 'implements AccessTokenContract')) {
                $this->skip($context, "{$relativePath} (already implements AccessTokenContract, skipped)");

                return;
            }

            if (! preg_match('/class\s+\w+Token\s+extends\s+BaseModel/', $source)) {
                $this->recordConflict($context, "{$relativePath} has an unexpected class declaration; skipping");

                return;
            }

            if (! str_contains($source, 'public static function hashFromPlain(')) {
                $this->recordConflict($context, "{$relativePath} does not look like the expected starter access-token model; skipping");

                return;
            }

            $source = $this->ensureImport($source, 'Illuminate\\Contracts\\Auth\\Authenticatable');
            $source = $this->ensureImport($source, 'Northwestern\\SysDev\\Chassis\\Contracts\\AccessTokenContract');

            $source = preg_replace(
                '/class\s+(\w+Token)\s+extends\s+BaseModel/',
                'class $1 extends BaseModel implements AccessTokenContract',
                $source,
                1,
            ) ?? $source;

            $source = preg_replace(
                '/\n}\s*$/',
                $this->contractMethodBlock($source) . "\n}\n",
                (string) $source,
                1,
            );

            if (! $context->isDryRun) {
                File::put($absolutePath, (string) $source);
            }

            $this->markFileModified($context);
            $this->success($context, "{$relativePath} (implements AccessTokenContract)");

            return;
        }
    }

    private function ensureImport(string $source, string $fqcn): string
    {
        if (str_contains($source, "use {$fqcn};")) {
            return $source;
        }

        $import = "use {$fqcn};\n";

        if (preg_match('/^namespace [^\n]+;\n\n((?:use [^\n]+;\n)+)/m', $source, $matches) === 1) {
            return str_replace($matches[1], $matches[1] . $import, $source);
        }

        return preg_replace(
            '/^(namespace [^\n]+;\n)/m',
            "$1\n{$import}",
            $source,
            1,
        ) ?? $source;
    }

    private function contractMethodBlock(string $source): string
    {
        $isLegacyValidityWindow = str_contains($source, 'valid_from') || str_contains($source, 'valid_to');
        $expiryExpression = $isLegacyValidityWindow
            ? '$this->valid_to?->isPast() ?? false'
            : '$this->expires_at?->isPast() ?? false';

        $activeExpression = $isLegacyValidityWindow
            ? <<<'PHP'
return $this->token_hash !== null
            && $this->revoked_at === null
            && $this->valid_from->isPast()
            && ! $this->isExpired();
PHP
            : <<<'PHP'
return $this->token_hash !== null
            && $this->revoked_at === null
            && ! $this->isExpired();
PHP;

        return <<<PHP

    public function getTokenHash(): string
    {
        return (string) \$this->token_hash;
    }

    public function isActive(): bool
    {
        {$activeExpression}
    }

    public function isExpired(): bool
    {
        return {$expiryExpression};
    }

    public function getAllowedIps(): ?array
    {
        return \$this->allowed_ips === [] ? null : \$this->allowed_ips;
    }

    public function getUserId(): int
    {
        return (int) \$this->user_id;
    }

    public function getTokenId(): int
    {
        return (int) \$this->getKey();
    }

    public function recordUsage(?string \$ipAddress): void
    {
        \$extra = ['last_used_at' => now()];

        if (\$ipAddress !== null && \Illuminate\Support\Facades\Schema::hasColumn(\$this->getTable(), 'last_ip_used')) {
            \$extra['last_ip_used'] = \$ipAddress;
        }

        \$this->increment(
            column: 'usage_count',
            extra: \$extra
        );
    }

    public function getUser(): ?Authenticatable
    {
        return \$this->user;
    }
PHP;
    }
}
