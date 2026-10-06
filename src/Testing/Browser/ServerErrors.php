<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Testing\Browser;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Testing\Fakes\ExceptionHandlerFake;
use LogicException;
use Throwable;

/**
 * The exceptions the application reported while serving the browser.
 *
 * Pest's browser plugin serves the application inside the test process, so `Exceptions::fake()`
 * records exceptions from the browser's requests, Livewire updates included. Without that, an
 * exception during a Livewire update shows the person an error and the test still passes.
 * Exceptions Laravel doesn't report, such as `abort(404)`, aren't recorded.
 */
final class ServerErrors
{
    /**
     * @return list<Throwable>
     *
     * @throws LogicException When exceptions aren't faked
     */
    public static function reported(): array
    {
        $handler = resolve(ExceptionHandler::class);

        if (! $handler instanceof ExceptionHandlerFake) {
            throw new LogicException(
                'Server errors are only recorded while exceptions are faked. Call Exceptions::fake() before visiting, '
                . 'or use the InteractsWithBrowser trait on the test case.',
            );
        }

        $reported = $handler->reported();

        return array_values(array_filter(
            is_array($reported) ? $reported : [],
            static fn (mixed $exception): bool => $exception instanceof Throwable,
        ));
    }

    public static function describe(Throwable $exception): string
    {
        return sprintf(
            '- %s: %s (%s:%d)',
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
        );
    }
}
