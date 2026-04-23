<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Seeding\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Orphan cleanup helper for idempotent seeders.
 *
 * After an upsert pass, call `deleteOrphans()` with the keys that should
 * remain. Rows whose key isn't in the kept list are deleted via each model's
 * `delete()` method — for models that use `SoftDeletes` this produces a soft
 * delete, otherwise a hard delete.
 *
 * Intended to be composed on `IdempotentSeeder` but usable directly on any
 * seeder that manages its own upserts and still wants reconciled state.
 */
trait CleansUpOrphans
{
    /**
     * Delete rows whose `$keyColumn` value isn't in `$keepKeys`.
     *
     * Returns the number of rows deleted. Uses per-model `delete()` so
     * soft-delete-aware models get soft-deleted, not hard-deleted.
     *
     * @param  class-string<Model>  $modelClass
     * @param  non-empty-string  $keyColumn
     * @param  list<mixed>  $keepKeys
     */
    protected function deleteOrphans(string $modelClass, string $keyColumn, array $keepKeys): int
    {
        $count = 0;

        $modelClass::whereNotIn($keyColumn, $keepKeys)->get()->each(function (Model $orphan) use (&$count): void {
            $orphan->delete();
            $count++;
        });

        return $count;
    }
}
