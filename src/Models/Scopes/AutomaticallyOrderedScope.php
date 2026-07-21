<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Scope that applies automatic ordering based on configurable columns.
 *
 * This scope can be automatically registered with the #[AutomaticallyOrdered] attribute.
 * It orders by a primary column, and then a secondary column.
 *
 * Column-existence checks are memoized per connection and table for the
 * lifetime of the process: global scopes run on every builder execution
 * (including eager loads), and schema lookups are live, uncached
 * information_schema queries. Call {@see flushCache()} if the schema
 * changes mid-process (e.g. tests that migrate between queries).
 *
 * @implements Scope<Model>
 */
class AutomaticallyOrderedScope implements Scope
{
    /**
     * Memoized column-existence checks, keyed by "connection.table.column".
     *
     * @var array<string, bool>
     */
    private static array $columnCache = [];

    /**
     * @param  string  $primary  The primary column to order by
     * @param  'asc'|'desc'  $primaryDirection  Sort direction for primary column
     * @param  string  $secondary  The secondary column to order by
     * @param  'asc'|'desc'  $secondaryDirection  Sort direction for secondary column
     */
    public function __construct(
        private readonly string $primary = 'order_index',
        private readonly string $primaryDirection = 'asc',
        private readonly string $secondary = 'label',
        private readonly string $secondaryDirection = 'asc',
    ) {
        //
    }

    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        if ($this->hasColumn($model, $this->primary)) {
            $builder->orderBy($this->primary, $this->primaryDirection);
        }

        if ($this->hasColumn($model, $this->secondary)) {
            $builder->orderBy($this->secondary, $this->secondaryDirection);
        }
    }

    /**
     * Forget all memoized column-existence checks.
     */
    public static function flushCache(): void
    {
        self::$columnCache = [];
    }

    /**
     * Check whether a column exists on the model's connection, memoizing the result.
     */
    private function hasColumn(Model $model, string $column): bool
    {
        $connection = $model->getConnection();
        $table = $model->getTable();
        $key = sprintf('%s.%s.%s', $connection->getName() ?? 'default', $table, $column);

        if (! array_key_exists($key, self::$columnCache)) {
            self::$columnCache[$key] = $connection->getSchemaBuilder()->hasColumn($table, $column);
        }

        return self::$columnCache[$key];
    }
}
