<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Testing\Browser;

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Expectation;
use Pest\TestSuite;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\ExpectationFailedException;
use UnexpectedValueException;

/**
 * Pest expectations for browser tests, registered once in `tests/Pest.php`:
 *
 *     Expectations::register();
 *
 *     expect(visit('/'))->toBeHealthy();
 *     expect(visit('/audits/1'))->toBeAccessible(exclude: ['diffs-container']);
 *     expect(FilamentPages::in('admin'))->toAllBeHealthy();
 */
final class Expectations
{
    public static function register(): void
    {
        expect()->extend('toBeAccessible', function (array $exclude = [], array $disableRules = []): Expectation {
            /** @var Expectation<mixed> $this */
            $page = Expectations::page($this->value);

            $violations = Accessibility::violations($page, Expectations::strings($exclude), Expectations::strings($disableRules));

            if ($violations !== []) {
                Health::fail($page, sprintf(
                    "Expected [%s] to have no accessibility violations, found %d:\n%s",
                    Health::pathOf($page),
                    count($violations),
                    implode("\n", array_map(static fn (AccessibilityViolation $violation): string => $violation->describe(), $violations)),
                ));
            }

            Assert::assertSame([], $violations);

            return $this;
        });

        expect()->extend('toHaveNoClientErrors', function (): Expectation {
            /** @var Expectation<mixed> $this */
            $page = Expectations::page($this->value);
            $errors = ClientErrors::of($page);

            if ($errors !== []) {
                Health::fail($page, sprintf(
                    "Expected [%s] to have no browser errors, found:\n- %s",
                    Health::pathOf($page),
                    implode("\n- ", $errors),
                ));
            }

            Assert::assertSame([], $errors);

            return $this;
        });

        expect()->extend('toHaveNoServerErrors', function (): Expectation {
            /** @var Expectation<mixed> $this */
            $page = Expectations::page($this->value);
            $exceptions = ServerErrors::reported();

            if ($exceptions !== []) {
                Health::fail($page, sprintf(
                    "Expected the application to report no exceptions while serving [%s], found:\n%s",
                    Health::pathOf($page),
                    implode("\n", array_map(ServerErrors::describe(...), $exceptions)),
                ));
            }

            Assert::assertSame([], $exceptions);

            return $this;
        });

        expect()->extend('toBeHealthy', function (array $exclude = []): Expectation {
            /** @var Expectation<mixed> $this */
            Expectations::assertHealthy(Expectations::page($this->value), Expectations::strings($exclude));

            return $this;
        });

        expect()->extend('toBeHealthyInEachTheme', function (array $exclude = []): Expectation {
            /** @var Expectation<mixed> $this */
            $path = Health::pathOf(Expectations::page($this->value));

            Expectations::assertHealthy(Expectations::visit($path)->inLightMode(), Expectations::strings($exclude));
            Expectations::assertHealthy(Expectations::visit($path)->inDarkMode(), Expectations::strings($exclude));

            return $this;
        });

        expect()->extend('toBeHealthyOnMobile', function (array $exclude = []): Expectation {
            /** @var Expectation<mixed> $this */
            $path = Health::pathOf(Expectations::page($this->value));
            // The device builder only becomes a page through a page method; light mode is the default.
            $mobile = Expectations::visit($path)->on()->mobile()->inLightMode();

            Expectations::assertHealthy(Expectations::page($mobile), Expectations::strings($exclude));

            return $this;
        });

        expect()->extend('toAllBeHealthy', function (array $exclude = []): Expectation {
            /** @var Expectation<mixed> $this */
            $paths = Expectations::strings(is_iterable($this->value) ? [...$this->value] : [$this->value]);
            $failures = [];

            foreach ($paths as $path) {
                $problems = Health::problems(
                    Expectations::visit($path),
                    Expectations::strings($exclude),
                    serverErrorsSince: count(ServerErrors::reported()),
                );

                if ($problems !== []) {
                    $failures[] = Health::describe($path, $problems);
                }
            }

            if ($failures !== []) {
                throw new ExpectationFailedException(sprintf(
                    "%d of %d pages aren't healthy.\n\n%s",
                    count($failures),
                    count($paths),
                    implode("\n\n", $failures),
                ));
            }

            Assert::assertSame([], $failures);

            return $this;
        });
    }

    /**
     * @internal
     *
     * @param  list<string>  $exclude
     */
    public static function assertHealthy(AwaitableWebpage|PendingAwaitablePage $page, array $exclude): void
    {
        $problems = Health::problems($page, $exclude);

        if ($problems !== []) {
            Health::fail($page, Health::describe(Health::pathOf($page), $problems));
        }

        Assert::assertSame([], $problems);
    }

    /**
     * The page under test, loaded: `visit()` doesn't load anything until the page is first used,
     * and an expectation about what happened while it loaded must not run before that.
     *
     * @internal
     */
    public static function page(mixed $value): AwaitableWebpage|PendingAwaitablePage
    {
        if ($value instanceof AwaitableWebpage || $value instanceof PendingAwaitablePage) {
            Health::playwright($value);

            return $value;
        }

        throw new UnexpectedValueException(sprintf(
            'Expected a page from visit(), got [%s].',
            get_debug_type($value),
        ));
    }

    /**
     * Visit a path in a new browser context, as the test's own `visit()` does.
     *
     * @internal
     */
    public static function visit(string $path): PendingAwaitablePage
    {
        $test = TestSuite::getInstance()->test;

        if (! $test instanceof \PHPUnit\Framework\TestCase || ! method_exists($test, 'visit')) {
            throw new UnexpectedValueException('Pages can only be visited from a browser test.');
        }

        $page = $test->visit($path);

        if (! $page instanceof PendingAwaitablePage) {
            throw new UnexpectedValueException('Expected visit() to return a page.');
        }

        return $page;
    }

    /**
     * @internal
     *
     * @param  array<mixed>  $values
     * @return list<string>
     */
    public static function strings(array $values): array
    {
        return array_values(array_filter($values, is_string(...)));
    }
}
