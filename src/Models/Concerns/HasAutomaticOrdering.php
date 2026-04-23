<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Northwestern\SysDev\Chassis\Attributes\AutomaticallyOrdered;
use Northwestern\SysDev\Chassis\Models\Scopes\AutomaticallyOrderedScope;
use ReflectionClass;

/**
 * Applies the AutomaticallyOrderedScope when the host model carries the
 * #[AutomaticallyOrdered] attribute.
 *
 * Use this trait on any Eloquent model — it does not require BaseModel.
 * Eloquent auto-invokes bootHasAutomaticOrdering() during model boot.
 *
 * ```php
 * #[AutomaticallyOrdered(primary: 'position', secondary: 'name')]
 * class Product extends Authenticatable
 * {
 *     use HasAutomaticOrdering;
 * }
 * ```
 *
 * BaseModel already uses this trait, so its subclasses pick up the behavior
 * automatically.
 *
 * @phpstan-require-extends Model
 */
trait HasAutomaticOrdering
{
    public static function bootHasAutomaticOrdering(): void
    {
        $reflection = new ReflectionClass(static::class);

        $attributes = $reflection->getAttributes(AutomaticallyOrdered::class);
        if (count($attributes) > 0) {
            /** @var AutomaticallyOrdered $attribute */
            $attribute = $attributes[0]->newInstance();
            static::addGlobalScope(
                new AutomaticallyOrderedScope(
                    primary: $attribute->primary,
                    primaryDirection: $attribute->primaryDirection,
                    secondary: $attribute->secondary,
                    secondaryDirection: $attribute->secondaryDirection
                )
            );
        }
    }
}
