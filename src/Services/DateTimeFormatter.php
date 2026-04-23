<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;

/**
 * Formats datetime values with timezone support.
 *
 * Decoupled from any specific User model — accepts a timezone string directly.
 * Consuming apps wrap this with their own user context as needed.
 */
class DateTimeFormatter
{
    public function __construct(
        private readonly ?string $defaultFormat = null,
    ) {
        //
    }

    /**
     * Format a datetime value with the given timezone.
     *
     * @param  CarbonInterface|string|null  $datetime  The datetime to format (Carbon instance or database string)
     * @param  string|null  $timezone  The timezone to display in (falls back to app.timezone config)
     * @param  string|null  $format  The date format string (falls back to constructor default)
     */
    public function datetime(CarbonInterface|string|null $datetime, ?string $timezone = null, ?string $format = null): string
    {
        if (! $datetime) {
            return 'n/a';
        }

        $format ??= $this->defaultFormat ?? 'M j, Y g:i A';
        $configTimezone = config('app.timezone', 'UTC');
        $timezone ??= is_string($configTimezone) ? $configTimezone : 'UTC';

        // Handle raw database strings that haven't been cast to Carbon
        if (is_string($datetime)) {
            $datetime = new Carbon($datetime);
        }

        return $datetime->copy()
            ->setTimezone($timezone)
            ->format($format);
    }

    /**
     * Build a Blade directive closure for @datetime().
     *
     * The compiled directive resolves the user's timezone via the authenticated
     * user's `timezone` property. If the user model doesn't have a `timezone`
     * property or there's no authenticated user, it falls back to app.timezone.
     *
     * Usage in Blade: @datetime($model->created_at)
     *
     * @return Closure(string): string
     */
    public function buildDatetimeDirective(): Closure
    {
        return function (string $expression): string {
            $class = '\\' . self::class;

            return "<?php echo resolve({$class}::class)->datetime({$expression}, auth()->user()?->timezone ?? null); ?>";
        };
    }
}
