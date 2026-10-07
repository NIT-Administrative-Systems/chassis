<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Models\Concerns;

use Illuminate\Support\Facades\Event;
use OwenIt\Auditing\Events\AuditCustom;

/**
 * Records a custom audit event on an auditable model, for a change its own model events don't
 * capture, such as a credential revoked on a person's behalf. The audit's user is whoever is
 * signed in. The model is left as it was found, so its next save is audited as that save.
 *
 * ```php
 * $client->recordCustomAudit('revoked', new: ['revoked' => true], old: ['revoked' => false]);
 * ```
 *
 * @phpstan-require-implements \OwenIt\Auditing\Contracts\Auditable
 */
trait RecordsCustomAudits
{
    /**
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $old
     */
    public function recordCustomAudit(string $event, array $new, array $old = []): void
    {
        $this->auditEvent = $event;
        $this->isCustomEvent = true;
        $this->auditCustomOld = $old;
        $this->auditCustomNew = $new;

        try {
            Event::dispatch(new AuditCustom($this));
        } finally {
            // As Laravel Auditing's own custom events do; left set, the next save is audited with these values.
            $this->isCustomEvent = false;
            $this->auditCustomOld = $this->auditCustomNew = null;
        }
    }
}
