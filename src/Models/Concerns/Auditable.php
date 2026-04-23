<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Models\Concerns;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Northwestern\SysDev\Chassis\ValueObjects\ApiRequestContext;
use Throwable;

/**
 * Enriched auditing trait that extends owen-it/laravel-auditing with:
 * - API request trace ID attachment
 * - Impersonation tracking (optional, requires lab404/laravel-impersonate)
 * - Livewire component name in audit URL (optional, requires livewire/livewire)
 * - Custom tag merging from other audit traits
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-require-implements \OwenIt\Auditing\Contracts\Auditable
 */
trait Auditable
{
    use \OwenIt\Auditing\Auditable;

    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function transformAudit(array $data): array
    {
        // Attach an API request trace ID if available
        if ($traceId = Context::get(ApiRequestContext::TRACE_ID)) {
            $data['trace_id'] = $traceId;
        }

        // Attach impersonation information if available
        if (app()->bound('impersonate')) {
            /** @var \Lab404\Impersonate\Services\ImpersonateManager $impersonate */
            $impersonate = resolve('impersonate');
            $data['impersonator_user_id'] = $impersonate->getImpersonatorId();
        }

        // Modify the URL for Livewire requests to include component information
        if (class_exists(\Livewire\Livewire::class)) {
            try {
                $url = $data['url'] ?? '';
                $updateUri = \Livewire\Livewire::getUpdateUri();
                if (is_string($url) && is_string($updateUri) && Str::contains($url, $updateUri)) {
                    $component = $this->extractLivewireComponentName();
                    if ($component !== null) {
                        $data['url'] = $url . '#' . $component;
                    }
                }
            } catch (Throwable) {
                // Livewire is installed but not fully booted (e.g. in tests)
            }
        }

        // Merge custom tags from audit traits (e.g., modification_origin from AuditsRoles)
        if (isset($this->auditCustomTags) && is_array($this->auditCustomTags)) {
            $rawTags = $data['tags'] ?? null;
            $existingTags = filled($rawTags) && is_string($rawTags) ? explode(',', $rawTags) : [];
            $allTags = array_merge($existingTags, $this->auditCustomTags);

            if (isset($this->auditCustomContext) && is_array($this->auditCustomContext)) {
                foreach ($this->auditCustomContext as $key => $value) {
                    $stringValue = is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
                    $allTags[] = $key . ': ' . $stringValue;
                }
            }

            /** @var array<string> $stringTags */
            $stringTags = array_map(static fn (mixed $tag): string => is_string($tag) ? $tag : (is_scalar($tag) ? (string) $tag : ''), $allTags);
            $data['tags'] = implode(',', $stringTags) ?: null;
        }

        return $data;
    }

    /**
     * Extract the Livewire component name from the request (if any).
     */
    protected function extractLivewireComponentName(): ?string
    {
        $livewireSnapshot = request('components.0.snapshot');

        if (! is_string($livewireSnapshot)) {
            return null;
        }

        try {
            $decodedSnapshot = json_decode($livewireSnapshot, true, 512, JSON_THROW_ON_ERROR);

            $name = data_get($decodedSnapshot, 'memo.name');

            return is_string($name) ? $name : null;
        } catch (Throwable) {
            return null;
        }
    }
}
