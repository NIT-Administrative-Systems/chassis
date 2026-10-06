<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Testing\Browser;

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;

/**
 * Runs axe on a browser test's page with the options Pest's own `assertNoAccessibilityIssues()`
 * doesn't take: elements to leave out, rules to turn off, and the least severe impact that fails.
 * Transitions and animations are settled while axe runs, so colors are checked as they end up.
 *
 * It uses the axe that Pest's browser plugin injects into every page. Set the defaults for a
 * whole suite once, in `tests/Pest.php`:
 *
 *     Accessibility::configure(disableRules: ['duplicate-id']);
 */
final class Accessibility
{
    private const string SCRIPT = <<<'JS'
        async ([exclude, rules]) => {
            if (typeof window.axe === 'undefined') {
                throw new Error('axe is not loaded on this page.');
            }

            // Settle every transition and animation first: axe reads colors as they are, and a
            // color partway through a transition can fail contrast the settled one passes.
            const settle = document.createElement('style');
            settle.textContent = '*, *::before, *::after { transition: none !important; animation: none !important; }';
            document.head.append(settle);
            await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));

            let result;

            try {
                const context = exclude.length > 0 ? { exclude: exclude.map((selector) => [selector]) } : document;
                result = await window.axe.run(context, { rules, resultTypes: ['violations'] });
            } finally {
                settle.remove();
            }

            return result.violations.map((violation) => ({
                rule: violation.id,
                impact: violation.impact ?? 'minor',
                help: violation.help,
                helpUrl: violation.helpUrl,
                targets: violation.nodes.map((node) => node.target.map(String).join(' ')),
            }));
        }
        JS;

    /** @var list<string> */
    private static array $disabledRules = [];

    /** @var list<string> */
    private static array $excluded = [];

    private static AccessibilityImpact $minimumImpact = AccessibilityImpact::Minor;

    /**
     * Set the defaults every accessibility check uses.
     *
     * @param  list<string>  $disableRules  axe rule IDs to skip on every page
     * @param  list<string>  $exclude  CSS selectors to leave out on every page
     * @param  AccessibilityImpact  $minimumImpact  The least severe violation that fails a check
     */
    public static function configure(
        array $disableRules = [],
        array $exclude = [],
        AccessibilityImpact $minimumImpact = AccessibilityImpact::Minor,
    ): void {
        self::$disabledRules = $disableRules;
        self::$excluded = $exclude;
        self::$minimumImpact = $minimumImpact;
    }

    /**
     * Restore the defaults: every rule, the whole page, and every impact.
     */
    public static function reset(): void
    {
        self::configure();
    }

    /**
     * The page's violations at or above the minimum impact.
     *
     * @param  list<string>  $exclude  CSS selectors to leave out, besides the configured ones
     * @param  list<string>  $disableRules  axe rule IDs to skip, besides the configured ones
     * @return list<AccessibilityViolation>
     */
    public static function violations(
        AwaitableWebpage|PendingAwaitablePage $page,
        array $exclude = [],
        array $disableRules = [],
    ): array {
        $rules = [];

        foreach ([...self::$disabledRules, ...$disableRules] as $rule) {
            $rules[$rule] = ['enabled' => false];
        }

        $playwright = Health::playwright($page);
        $playwright->waitForLoadState('networkidle');

        $results = $playwright->evaluate(self::SCRIPT, [
            array_values(array_unique([...self::$excluded, ...$exclude])),
            (object) $rules,
        ]);

        $violations = [];

        foreach (is_array($results) ? $results : [] as $result) {
            if (! is_array($result)) {
                continue;
            }

            $violation = self::violation($result);

            if ($violation->impact->isAtLeast(self::$minimumImpact)) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * @param  array<mixed>  $result
     */
    private static function violation(array $result): AccessibilityViolation
    {
        $targets = is_array($result['targets'] ?? null) ? $result['targets'] : [];

        return new AccessibilityViolation(
            rule: self::string($result['rule'] ?? null),
            impact: AccessibilityImpact::tryFrom(self::string($result['impact'] ?? null)) ?? AccessibilityImpact::Minor,
            help: self::string($result['help'] ?? null),
            helpUrl: self::string($result['helpUrl'] ?? null),
            targets: array_values(array_map(self::string(...), $targets)),
        );
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
