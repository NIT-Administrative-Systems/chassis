<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Fixtures\Validators;

use Northwestern\SysDev\Chassis\Attributes\ValidatesConfig;
use Northwestern\SysDev\Chassis\Contracts\ConfigValidator;

#[ValidatesConfig(description: 'Cache Store')]
class TestCacheValidator implements ConfigValidator
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
        return 'Cache is working';
    }

    public function errorMessage(): string
    {
        return 'Cache is not working';
    }

    public function hints(): array
    {
        return [];
    }
}
