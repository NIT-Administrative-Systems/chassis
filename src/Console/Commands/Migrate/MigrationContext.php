<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate;

use Illuminate\Console\Command;

/**
 * Mutable data object passed to each migration step.
 *
 * Steps read and write these properties directly to share
 * state with the orchestrator (counters, change log, conflicts).
 */
class MigrationContext
{
    public int $namespacesRewritten = 0;

    public int $filesDeleted = 0;

    public int $filesScaffolded = 0;

    /** @var list<array{string, int, string}> */
    public array $changeLog = [];

    /** @var list<string> */
    public array $conflicts = [];

    public function __construct(
        public readonly bool $isDryRun,
        public readonly Command $command,
    ) {
    }
}
