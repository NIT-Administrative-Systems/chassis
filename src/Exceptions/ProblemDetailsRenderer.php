<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Exceptions;

use ErrorException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Northwestern\SysDev\Chassis\Http\Responses\ProblemDetails;
use Northwestern\SysDev\Chassis\ValueObjects\ApiRequestContext;
use PDOException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

/**
 * Converts API exceptions into RFC 9457 Problem Details responses.
 *
 * Only applies to API requests (under `/api/*` or requests that want JSON).
 * Optionally tags failures with a reason string for enriched request logging.
 */
class ProblemDetailsRenderer
{
    public function __construct(
        private ?string $authRealm = null,
        private string $apiPrefix = 'api',
    ) {
        //
    }

    private function resolveAuthRealm(): string
    {
        if ($this->authRealm !== null) {
            return $this->authRealm;
        }

        // Check common config locations across starter versions
        $configValue = config('api.auth_realm', config('auth.auth_realm'));

        if (is_string($configValue) && $configValue !== '') {
            return $configValue;
        }

        $appName = config('app.name');

        return (is_string($appName) ? $appName : 'App') . ' API';
    }

    /**
     * Maps various exceptions to RFC 9457 Problem Details responses.
     */
    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is($this->apiPrefix . '/*') && ! $request->wantsJson()) {
            return null;
        }

        // Let subclasses handle app-specific exceptions before the defaults.
        $custom = $this->mapCustomExceptions($e, $request);
        if ($custom instanceof JsonResponse) {
            return $custom;
        }

        return match (true) {
            // --- 4XX Client Errors ---
            $e instanceof ValidationException => tap(
                ProblemDetails::unprocessableEntity(errors: $this->normalizeValidationErrors($e->errors())),
                fn () => $this->setFailure('validation-failed')
            ),

            // 401 & 403 Authentication/Authorization
            $e instanceof AuthenticationException => ProblemDetails::unauthorized(realm: $this->resolveAuthRealm()),

            $e instanceof UnauthorizedHttpException => ProblemDetails::unauthorized(
                detail: $e->getMessage() ?: 'Authentication required.',
                realm: $this->resolveAuthRealm(),
                headers: $this->normalizeHeaders($e->getHeaders())
            ),

            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => tap(
                ProblemDetails::forbidden(
                    detail: $e->getMessage() ?: 'You do not have permission to access this resource.'
                ),
                fn () => $this->setFailure('unauthorized')
            ),

            // 404 Not Found & 409 Conflict
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => ProblemDetails::notFound(),

            $e instanceof ConflictHttpException => tap(
                ProblemDetails::conflict(
                    detail: $e->getMessage() ?: 'Conflict'
                ),
                fn () => $this->setFailure('conflict')
            ),

            // 405 Method Not Allowed
            $e instanceof MethodNotAllowedHttpException => ProblemDetails::methodNotAllowed(
                allowedMethods: is_string($e->getHeaders()['Allow'] ?? null) ? $e->getHeaders()['Allow'] : [],
                detail: 'The HTTP method used is not supported for this endpoint.'
            ),

            // 400 Bad Request
            $e instanceof BadRequestException,
            $e instanceof ErrorException,
            $e instanceof NotAcceptableHttpException,
            $e instanceof InvalidArgumentException,
            $e instanceof LogicException => ProblemDetails::badRequest(
                detail: $e->getMessage() ?: 'The request could not be understood by the server.'
            ),

            // 413 Payload Too Large
            $e instanceof PostTooLargeException => ProblemDetails::payloadTooLarge(),

            // 429 Rate Limiting
            $e instanceof ThrottleRequestsException => ProblemDetails::tooManyRequests(
                detail: 'Too many requests. Please try again later.',
                retryAfter: $this->extractRetryAfter($e->getHeaders())
            ),

            // --- 5XX Server Errors ---
            // 503 Service Unavailable
            $e instanceof ServiceUnavailableHttpException => ProblemDetails::serviceUnavailable(),

            $e instanceof HttpExceptionInterface => tap(
                ProblemDetails::response(
                    status: min(599, max(100, $e->getStatusCode())),
                    title: 'HTTP Error',
                    detail: $e->getMessage() ?: null,
                    headers: $this->normalizeHeaders($e->getHeaders())
                ),
                fn () => $this->setFailure('server-error')
            ),

            // Catch specific database exceptions
            $e instanceof PDOException => tap(
                ProblemDetails::internalServerError(
                    detail: 'A database error occurred while processing the request.'
                ),
                fn () => $this->setFailure('database-error')
            ),

            // 500 Internal Server Error
            default => tap(
                ProblemDetails::internalServerError(),
                fn () => $this->setFailure('server-error')
            ),
        };
    }

    /**
     * Map an app-specific exception to a Problem Details response.
     *
     * The default implementation returns null, which falls through to the
     * built-in match for Laravel/Symfony/PDO exceptions. Override in a
     * subclass to handle domain exceptions without re-implementing the
     * chassis defaults:
     *
     * ```php
     * class AppProblemDetailsRenderer extends ProblemDetailsRenderer
     * {
     *     protected function mapCustomExceptions(Throwable $e, Request $request): ?JsonResponse
     *     {
     *         return match (true) {
     *             $e instanceof TokenBudgetExceededException => tap(
     *                 ProblemDetails::tooManyRequests(detail: $e->getMessage()),
     *                 fn () => $this->setFailure('token-budget-exceeded'),
     *             ),
     *             default => null,
     *         };
     *     }
     * }
     * ```
     *
     * The hook runs after the API-request gate, so subclasses don't have to
     * re-check whether the request is eligible for a Problem Details response.
     */
    protected function mapCustomExceptions(Throwable $e, Request $request): ?JsonResponse
    {
        return null;
    }

    /**
     * Normalize validation errors to the expected type.
     *
     * @param  array<mixed>  $errors
     * @return array<string, list<string>>
     */
    private function normalizeValidationErrors(array $errors): array
    {
        $normalized = [];
        foreach ($errors as $field => $messages) {
            $key = is_string($field) ? $field : (string) $field;
            if (is_array($messages)) {
                $normalized[$key] = array_values(array_map(
                    fn (mixed $msg): string => is_string($msg) ? $msg : (is_scalar($msg) ? (string) $msg : ''),
                    $messages
                ));
            } else {
                $normalized[$key] = [is_string($messages) ? $messages : (is_scalar($messages) ? (string) $messages : '')];
            }
        }

        return $normalized;
    }

    /**
     * Normalize HTTP exception headers to string values.
     *
     * @param  array<mixed>  $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalizedKey = is_string($key) ? $key : (string) $key;
            $normalized[$normalizedKey] = is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
        }

        return $normalized;
    }

    /**
     * Extract the Retry-After value from exception headers.
     *
     * @param  array<mixed>  $headers
     * @return positive-int
     */
    private function extractRetryAfter(array $headers): int
    {
        $retryAfter = $headers['Retry-After'] ?? 60;
        $value = is_numeric($retryAfter) ? (int) $retryAfter : 60;

        return max(1, $value);
    }

    /**
     * Write a failure reason into the shared API request context, but do not
     * overwrite an existing one (e.g. from the authentication middleware).
     *
     * Subclasses can call this from `mapCustomExceptions()` to tag app-specific
     * failures so downstream logging/observability sees consistent reasons.
     */
    protected function setFailure(string $failure): void
    {
        if (Context::has(ApiRequestContext::FAILURE_REASON)) {
            return;
        }

        Context::add(ApiRequestContext::FAILURE_REASON, $failure);
    }
}
