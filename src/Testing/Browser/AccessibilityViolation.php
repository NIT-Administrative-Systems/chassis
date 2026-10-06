<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Testing\Browser;

/**
 * One axe rule a page breaks, with the elements that break it.
 */
final readonly class AccessibilityViolation
{
    /**
     * @param  list<string>  $targets  A CSS selector for each failing element
     */
    public function __construct(
        public string $rule,
        public AccessibilityImpact $impact,
        public string $help,
        public string $helpUrl,
        public array $targets,
    ) {
        //
    }

    public function describe(): string
    {
        $lines = [
            sprintf('- %s (%s): %s', $this->rule, $this->impact->value, $this->help),
            '  ' . $this->helpUrl,
        ];

        foreach ($this->targets as $target) {
            $lines[] = '  · ' . $target;
        }

        return implode("\n", $lines);
    }
}
