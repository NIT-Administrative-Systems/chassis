<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\ValueObjects;

use Northwestern\SysDev\Chassis\Contracts\ConfigValidator;

/**
 * Pairs a discovered config validator with its attribute metadata.
 */
readonly class ResolvedValidator
{
    public function __construct(
        public ConfigValidator $validator,
        public string $description,
    ) {
        //
    }
}
