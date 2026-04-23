<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Seeding;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Northwestern\SysDev\Chassis\Contracts\IdempotentSeederInterface;
use Northwestern\SysDev\Chassis\Seeding\Concerns\CleansUpOrphans;
use Northwestern\SysDev\Chassis\Seeding\Concerns\PerformsIdempotentUpserts;

/**
 * Base class for production-safe, idempotent database seeders.
 *
 * Upserts rows by a unique `slugColumn`, handles `SoftDeletes` transparently,
 * optionally deletes orphaned rows, and exposes an `afterUpsert()` hook for
 * per-row work (syncing relationships, dispatching events).
 *
 * For seeders whose needs don't fit the standard template (composite keys,
 * bespoke error recovery, custom persistence), `use` the
 * `PerformsIdempotentUpserts` and `CleansUpOrphans` traits directly on a
 * seeder that implements `IdempotentSeederInterface`.
 */
abstract class IdempotentSeeder extends Seeder implements IdempotentSeederInterface
{
    use CleansUpOrphans;
    use PerformsIdempotentUpserts;

    /**
     * Model to use for select/update/insert/delete operations.
     *
     * @var class-string<Model>
     */
    protected string $model;

    /**
     * The column name used to identify existing records.
     *
     * This column should contain unique identifiers that remain
     * stable across environments (e.g., 'slug', 'code', 'name').
     *
     * @var non-empty-string
     */
    protected string $slugColumn;

    /**
     * Whether to delete records not present in the seed data.
     *
     * When true (default), any records in the database whose `slugColumn`
     * value is not in the current `data()` return value will be deleted.
     * Set to false to preserve existing records not in the seed data.
     */
    protected bool $deleteOrphans = true;

    /**
     * Keys that appear in `data()` rows but are not columns on the model.
     *
     * Transient keys are stripped before the upsert and passed through to
     * `afterUpsert()` intact so the hook can consume them (e.g. to sync
     * relationships, dispatch events).
     *
     * @var list<string>
     */
    protected array $transient = [];

    public function run(): void
    {
        $seenKeys = [];

        foreach ($this->data() as $row) {
            /** @var array<string, mixed> $columns */
            $columns = Arr::except($row, $this->transient);

            $model = $this->upsertBySlug($this->model, $columns, $this->slugColumn);
            $this->afterUpsert($model, $row);

            if (array_key_exists($this->slugColumn, $row)) {
                $seenKeys[] = $row[$this->slugColumn];
            }
        }

        if ($this->deleteOrphans) {
            $this->deleteOrphans($this->model, $this->slugColumn, $seenKeys);
        }
    }

    /**
     * The rows to upsert.
     *
     * @return array<int, array<string, mixed>>
     */
    abstract public function data(): array;

    /**
     * Hook called after each row is upserted.
     *
     * Default implementation is a no-op. Override to sync relationships,
     * dispatch events, or do other per-row work. The `$row` argument is
     * the original row from `data()`, including any transient keys.
     *
     * @param  array<string, mixed>  $row
     */
    protected function afterUpsert(Model $model, array $row): void
    {
        //
    }
}
