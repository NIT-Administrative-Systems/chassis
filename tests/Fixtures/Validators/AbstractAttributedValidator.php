<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Fixtures\Validators;

use Northwestern\SysDev\Chassis\Attributes\ValidatesConfig;
use Northwestern\SysDev\Chassis\Contracts\ConfigValidator;

/**
 * Fixture: has the #[ValidatesConfig] attribute and implements the contract,
 * but is abstract — the resolver must skip it.
 */
#[ValidatesConfig(description: 'Abstract Validator Should Be Ignored')]
abstract class AbstractAttributedValidator implements ConfigValidator
{
    public function shouldRun(): bool
    {
        return true;
    }

    public function validate(): bool
    {
        return true;
    }

    public function successMessage(): string
    {
        return '';
    }

    public function errorMessage(): string
    {
        return '';
    }

    public function hints(): array
    {
        return [];
    }
}
