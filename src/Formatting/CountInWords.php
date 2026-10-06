<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Formatting;

use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * A count and its noun for a sentence, in Northwestern style: one through nine spelled out,
 * numerals from 10, and a real plural ("one minute", "five seconds", "30 seconds").
 *
 * Spelling out a number needs PHP's `intl` extension.
 */
final class CountInWords
{
    public static function of(int $count, string $noun): string
    {
        return Number::spell($count, until: 10) . ' ' . Str::plural($noun, $count);
    }
}
