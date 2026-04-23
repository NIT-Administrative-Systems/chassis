<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Seeding\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;

/**
 * Upsert helpers for idempotent seeders.
 *
 * Provides `upsertBySlug()` for single-column uniqueness and
 * `upsertByCompositeKey()` for multi-column uniqueness. Both transparently
 * handle models that use `SoftDeletes` — a soft-deleted row matching the key
 * is restored (`deleted_at` reset to null) instead of creating a duplicate.
 *
 * Intended to be composed on `IdempotentSeeder` but usable directly on any
 * seeder that needs an idempotent upsert without the full base class.
 */
trait PerformsIdempotentUpserts
{
    /**
     * Upsert a row by a single unique key column.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $attributes  The full row (key column + values).
     * @param  non-empty-string  $keyColumn  The unique column used to match existing rows.
     */
    protected function upsertBySlug(string $modelClass, array $attributes, string $keyColumn): Model
    {
        return $this->upsertByCompositeKey($modelClass, $attributes, [$keyColumn]);
    }

    /**
     * Upsert a row by multiple unique key columns.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $attributes
     * @param  non-empty-list<non-empty-string>  $keyColumns
     */
    protected function upsertByCompositeKey(string $modelClass, array $attributes, array $keyColumns): Model
    {
        /** @var array<string, mixed> $keyValues */
        $keyValues = Arr::only($attributes, $keyColumns);

        /** @var array<string, mixed> $values */
        $values = Arr::except($attributes, $keyColumns);

        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $values[$this->resolveDeletedAtColumn($modelClass)] = null;

            /** @var Builder<Model> $builder */
            $builder = $modelClass::withTrashed(); // @phpstan-ignore staticMethod.notFound

            return $builder->updateOrCreate($keyValues, $values);
        }

        return $modelClass::updateOrCreate($keyValues, $values);
    }

    /**
     * Wrapped so the string return type narrows the call for PHPStan — the
     * ignore below suppresses the error but leaves the value mixed, which
     * then fails when used as an array key at the call site.
     *
     * @param  class-string<Model>  $modelClass
     */
    private function resolveDeletedAtColumn(string $modelClass): string
    {
        return (new $modelClass())->getDeletedAtColumn(); // @phpstan-ignore method.notFound, return.type
    }
}
