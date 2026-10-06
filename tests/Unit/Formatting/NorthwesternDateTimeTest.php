<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Unit\Formatting;

use Carbon\CarbonImmutable;
use Northwestern\SysDev\Chassis\Formatting\NorthwesternDateTime;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(NorthwesternDateTime::class)]
class NorthwesternDateTimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'America/Chicago'));
    }

    #[DataProvider('times')]
    public function test_times_follow_northwestern_style(string $moment, string $expected): void
    {
        $this->assertSame($expected, NorthwesternDateTime::time(CarbonImmutable::parse($moment, 'America/Chicago'), 'America/Chicago'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function times(): array
    {
        return [
            'minutes' => ['2026-10-10 10:12', '10:12 a.m.'],
            'on the hour' => ['2026-10-10 16:00', '4 p.m.'],
            'noon' => ['2026-10-10 12:00', 'noon'],
            'midnight' => ['2026-10-10 00:00', 'midnight'],
        ];
    }

    public function test_the_time_comes_before_the_date_and_this_year_is_left_out(): void
    {
        $moment = CarbonImmutable::parse('2026-10-10 15:12', 'UTC');

        $this->assertSame('10:12 a.m. CDT Saturday, October 10', NorthwesternDateTime::format($moment, 'America/Chicago'));
        $this->assertSame('10:12 a.m. Saturday, October 10', NorthwesternDateTime::format($moment, 'America/Chicago', withZone: false));
    }

    public function test_another_year_is_spelled_out(): void
    {
        $moment = CarbonImmutable::parse('2027-01-04 09:00', 'America/Chicago');

        $this->assertSame('Monday, January 4, 2027', NorthwesternDateTime::date($moment, 'America/Chicago'));
        $this->assertSame('January 4, 2027', NorthwesternDateTime::date($moment, 'America/Chicago', withWeekday: false));
    }

    public function test_it_defaults_to_the_application_timezone(): void
    {
        config(['app.timezone' => 'America/Chicago']);

        $this->assertSame('9 a.m.', NorthwesternDateTime::time(CarbonImmutable::parse('2026-10-10 14:00', 'UTC')));
    }

    public function test_it_falls_back_to_utc_without_an_application_timezone(): void
    {
        config(['app.timezone' => null]);

        $this->assertSame('2 p.m.', NorthwesternDateTime::time(CarbonImmutable::parse('2026-10-10 14:00', 'UTC')));
    }
}
