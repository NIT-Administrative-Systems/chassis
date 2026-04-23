<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Fixtures\Validators;

use Northwestern\SysDev\Chassis\Contracts\ConfigValidator;

/**
 * Fixture: implements ConfigValidator but has no #[ValidatesConfig] attribute.
 */
class UnattributedValidator implements ConfigValidator
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
