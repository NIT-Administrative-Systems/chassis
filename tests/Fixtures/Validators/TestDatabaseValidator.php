<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Fixtures\Validators;

use Northwestern\SysDev\Chassis\Attributes\ValidatesConfig;
use Northwestern\SysDev\Chassis\Contracts\ConfigValidator;

#[ValidatesConfig(description: 'Database Connection')]
class TestDatabaseValidator implements ConfigValidator
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
        return 'Connected to database';
    }

    public function errorMessage(): string
    {
        return 'Cannot connect to database';
    }

    public function hints(): array
    {
        return ['Check DB_DATABASE in .env'];
    }
}
