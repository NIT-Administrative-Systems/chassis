<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Unit\Enums;

use Filament\Support\Icons\Heroicon;
use Northwestern\SysDev\Chassis\Enums\ApiRequestFailure;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ApiRequestFailure::class)]
class ApiRequestFailureTest extends TestCase
{
    public function test_all_cases_are_backed_by_non_empty_string_values(): void
    {
        foreach (ApiRequestFailure::cases() as $case) {
            $this->assertIsString($case->value);
            $this->assertNotEmpty($case->value);
        }
    }

    public function test_all_cases_have_non_empty_labels(): void
    {
        foreach (ApiRequestFailure::cases() as $case) {
            $label = $case->getLabel();

            $this->assertIsString($label);
            $this->assertNotEmpty($label);
        }
    }

    public function test_all_cases_have_non_empty_descriptions(): void
    {
        foreach (ApiRequestFailure::cases() as $case) {
            $description = $case->getDescription();

            $this->assertIsString($description);
            $this->assertNotEmpty($description);
        }
    }

    public function test_all_cases_have_icons(): void
    {
        foreach (ApiRequestFailure::cases() as $case) {
            $this->assertInstanceOf(Heroicon::class, $case->getIcon());
        }
    }

    public function test_all_cases_return_danger_color(): void
    {
        foreach (ApiRequestFailure::cases() as $case) {
            $this->assertSame('danger', $case->getColor());
        }
    }

    public function test_can_be_constructed_from_a_string_value(): void
    {
        $case = ApiRequestFailure::from('invalid-header-format');

        $this->assertSame(ApiRequestFailure::InvalidHeaderFormat, $case);
    }

    public function test_try_from_returns_null_for_unknown_value(): void
    {
        $this->assertNull(ApiRequestFailure::tryFrom('nonexistent'));
    }

    public function test_each_case_has_a_unique_backing_value(): void
    {
        $values = array_map(fn (ApiRequestFailure $c) => $c->value, ApiRequestFailure::cases());

        $this->assertSame(array_unique($values), $values);
    }
}
