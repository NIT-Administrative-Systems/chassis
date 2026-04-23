<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Models;

use Illuminate\Database\Eloquent\Model;
use Northwestern\SysDev\Chassis\Models\Concerns\Auditable;
use Northwestern\SysDev\Chassis\Models\Concerns\HasAutomaticOrdering;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Base model with automatic audit logging and attribute-driven global scopes.
 *
 * All models extending this class will automatically log create, update, and delete
 * operations to the `audits` table for a complete audit trail of all data changes.
 *
 * Supports the #[AutomaticallyOrdered] attribute for declarative query ordering.
 *
 * Audit enrichments (optional based on installed packages):
 * - API request trace ID in audit records
 * - Impersonator tracking (requires lab404/laravel-impersonate)
 * - Livewire component names in audit URLs (requires livewire/livewire)
 *
 * To use #[AutomaticallyOrdered] on a model that cannot extend BaseModel
 * (e.g. User extending Authenticatable), apply the HasAutomaticOrdering
 * trait directly.
 */
abstract class BaseModel extends Model implements AuditableContract
{
    use Auditable;
    use HasAutomaticOrdering;
}
