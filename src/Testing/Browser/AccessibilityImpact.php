<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Testing\Browser;

/**
 * The impact axe assigns to a violation, from least to most severe.
 */
enum AccessibilityImpact: string
{
    case Minor = 'minor';
    case Moderate = 'moderate';
    case Serious = 'serious';
    case Critical = 'critical';

    /**
     * Whether a violation of this impact is at least as severe as the given one.
     */
    public function isAtLeast(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Minor => 0,
            self::Moderate => 1,
            self::Serious => 2,
            self::Critical => 3,
        };
    }
}
