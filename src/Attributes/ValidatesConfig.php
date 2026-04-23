<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Attributes;

use Attribute;

/**
 * Marks a config validator for automatic discovery.
 *
 * Validators decorated with this attribute are automatically discovered and
 * executed during configuration validation.
 *
 * Requirements:
 * - Must implement \Northwestern\SysDev\Chassis\Contracts\ConfigValidator
 */
#[Attribute(Attribute::TARGET_CLASS)]
readonly class ValidatesConfig
{
    /**
     * @param  non-empty-string  $description  Human-readable label displayed during validation output
     */
    public function __construct(
        public string $description,
    ) {
        //
    }
}
