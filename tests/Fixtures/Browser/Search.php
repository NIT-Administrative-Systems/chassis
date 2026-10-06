<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Fixtures\Browser;

use Livewire\Component;
use RuntimeException;

/**
 * A Livewire component with a debounced field, a slow action, and an action that fails on the
 * server.
 */
class Search extends Component
{
    public string $query = '';

    public function slow(): void
    {
        \Illuminate\Support\Sleep::usleep(300_000);

        $this->query = 'slow answer';
    }

    public function explode(): void
    {
        throw new RuntimeException('The search exploded.');
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                <label for="query">Query</label>
                <input id="query" type="text" wire:model.live.debounce.400ms="query" data-testid="query">
                <p data-testid="echo">Searching for: {{ $query }}</p>
                <button type="button" wire:click="slow" data-testid="slow">Slow</button>
                <button type="button" wire:click="explode" data-testid="explode">Explode</button>
            </div>
            BLADE;
    }
}
