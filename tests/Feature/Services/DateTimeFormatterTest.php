<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Services;

use Carbon\Carbon;
use Northwestern\SysDev\Chassis\Services\DateTimeFormatter;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DateTimeFormatter::class)]
class DateTimeFormatterTest extends TestCase
{
    public function test_formats_a_carbon_instance(): void
    {
        $formatter = new DateTimeFormatter();
        $dt = Carbon::create(2025, 6, 15, 14, 30, 0, 'UTC');

        $result = $formatter->datetime($dt, 'UTC');

        $this->assertSame('Jun 15, 2025 2:30 PM', $result);
    }

    public function test_formats_a_string_datetime(): void
    {
        $formatter = new DateTimeFormatter();

        $result = $formatter->datetime('2025-06-15 14:30:00', 'UTC');

        $this->assertSame('Jun 15, 2025 2:30 PM', $result);
    }

    public function test_returns_na_for_null(): void
    {
        $formatter = new DateTimeFormatter();

        $this->assertSame('n/a', $formatter->datetime(null));
    }

    public function test_uses_custom_timezone(): void
    {
        $formatter = new DateTimeFormatter();
        $dt = Carbon::create(2025, 6, 15, 14, 0, 0, 'UTC');

        $result = $formatter->datetime($dt, 'America/Chicago');

        $this->assertSame('Jun 15, 2025 9:00 AM', $result);
    }

    public function test_uses_custom_format(): void
    {
        $formatter = new DateTimeFormatter();
        $dt = Carbon::create(2025, 6, 15, 14, 30, 0, 'UTC');

        $result = $formatter->datetime($dt, 'UTC', 'Y-m-d H:i');

        $this->assertSame('2025-06-15 14:30', $result);
    }

    public function test_uses_constructor_default_format(): void
    {
        $formatter = new DateTimeFormatter('Y-m-d');
        $dt = Carbon::create(2025, 6, 15, 14, 30, 0, 'UTC');

        $result = $formatter->datetime($dt, 'UTC');

        $this->assertSame('2025-06-15', $result);
    }

    public function test_falls_back_to_app_timezone_config(): void
    {
        config(['app.timezone' => 'America/New_York']);

        $formatter = new DateTimeFormatter();
        $dt = Carbon::create(2025, 6, 15, 14, 0, 0, 'UTC');

        $result = $formatter->datetime($dt);

        $this->assertSame('Jun 15, 2025 10:00 AM', $result);
    }

    public function test_build_datetime_directive_returns_blade_compiled_string(): void
    {
        $formatter = new DateTimeFormatter();

        $directive = $formatter->buildDatetimeDirective();

        $expression = '$model->created_at';
        $expectedClass = '\\' . DateTimeFormatter::class;

        $expected = "<?php echo resolve({$expectedClass}::class)->datetime({$expression}, auth()->user()?->timezone ?? null); ?>";

        $this->assertSame($expected, $directive($expression));
    }
}
