<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Exceptions;

use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * Reports exceptions to Sentry with enriched user context.
 *
 * Works out of the box with sensible defaults (id + email).
 * Override `userContext()` to customize which user fields are sent.
 *
 * ```php
 * class SentryExceptionHandler extends \Northwestern\SysDev\Chassis\Exceptions\SentryExceptionHandler
 * {
 *     protected function userContext(Authenticatable $user): array
 *     {
 *         return [
 *             'id' => $user->getAuthIdentifier(),
 *             'username' => $user->username,
 *             'email' => $user->email,
 *         ];
 *     }
 * }
 * ```
 */
class SentryExceptionHandler
{
    public function report(Throwable $exception): void
    {
        if (! app()->bound('sentry')) {
            return;
        }

        $this->addSentryContext();

        $sentry = resolve('sentry');
        if (is_object($sentry) && method_exists($sentry, 'captureException')) {
            $sentry->captureException($exception);
        }
    }

    /**
     * Return the user context array to attach to Sentry reports.
     *
     * Override this method to customize the user fields sent to Sentry.
     * The default implementation sends the auth identifier and email.
     *
     * @return array<string, mixed>
     */
    protected function userContext(Authenticatable $user): array
    {
        return [
            'id' => $user->getAuthIdentifier(),
        ];
    }

    private function addSentryContext(): void
    {
        if (! app()->bound('app') || ! auth()->hasResolvedGuards()) {
            return;
        }

        if (! auth()->check()) {
            return;
        }

        $user = auth()->user();

        if (! $user instanceof Authenticatable) {
            return;
        }

        if (! function_exists('Sentry\configureScope')) {
            return;
        }

        // @codeCoverageIgnoreStart
        // The remainder requires the sentry/sentry-laravel package, which is
        // a `suggest` dependency and not installed in the chassis's test env.
        if (! class_exists('\Sentry\State\Scope')) {
            return;
        }

        \Sentry\configureScope(function (object $scope) use ($user): void {
            if (method_exists($scope, 'setUser')) {
                $scope->setUser($this->userContext($user));
            }
        });
        // @codeCoverageIgnoreEnd
    }
}
