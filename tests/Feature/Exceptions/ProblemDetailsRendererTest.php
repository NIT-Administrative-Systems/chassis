<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Exceptions;

use ErrorException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Northwestern\SysDev\Chassis\Exceptions\ProblemDetailsRenderer;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use Northwestern\SysDev\Chassis\ValueObjects\ApiRequestContext;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

#[CoversClass(ProblemDetailsRenderer::class)]
class ProblemDetailsRendererTest extends TestCase
{
    private ProblemDetailsRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = new ProblemDetailsRenderer(authRealm: 'Test Realm', apiPrefix: 'api');
    }

    /** @param array<string, string> $server */
    private function renderForRequest(Throwable $e, string $uri, array $server = []): ?JsonResponse
    {
        $request = Request::create($uri, 'GET', [], [], [], $server);

        $this->app->instance('request', $request);

        return $this->renderer->render($e, $request);
    }

    private function renderForApi(Throwable $e, string $uri = '/api/test'): JsonResponse
    {
        $response = $this->renderForRequest($e, $uri, [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $this->assertNotNull($response);
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));

        return $response;
    }

    #[DataProvider('simpleExceptionMappingProvider')]
    public function test_exceptions_are_mapped_to_expected_status_and_title(
        Throwable $exception,
        int $expectedStatus,
        string $expectedTitle
    ): void {
        $response = $this->renderForApi($exception);

        $this->assertSame($expectedStatus, $response->getStatusCode());

        $data = $response->getData(true);

        $this->assertSame($expectedStatus, $data['status']);
        $this->assertSame($expectedTitle, $data['title']);
        $this->assertSame('/api/test', $data['instance']);
        $this->assertSame('about:blank', $data['type']);
    }

    /** @return array<string, array{0: Throwable, 1: int, 2: string}> */
    public static function simpleExceptionMappingProvider(): array
    {
        return [
            'authentication' => [
                new AuthenticationException(),
                401,
                'Unauthorized',
            ],
            'unauthorized http exception' => [
                new UnauthorizedHttpException('Bearer'),
                401,
                'Unauthorized',
            ],
            'authorization (AuthorizationException)' => [
                new AuthorizationException(),
                403,
                'Forbidden',
            ],
            'authorization (AccessDeniedHttpException)' => [
                new AccessDeniedHttpException(),
                403,
                'Forbidden',
            ],
            'model not found' => [
                new ModelNotFoundException(),
                404,
                'Not Found',
            ],
            'not found http' => [
                new NotFoundHttpException(),
                404,
                'Not Found',
            ],
            'conflict' => [
                new ConflictHttpException(),
                409,
                'Conflict',
            ],
            'bad request (BadRequestException)' => [
                new BadRequestException(),
                400,
                'Bad Request',
            ],
            'bad request (ErrorException)' => [
                new ErrorException('boom'),
                400,
                'Bad Request',
            ],
            'bad request (NotAcceptableHttpException)' => [
                new NotAcceptableHttpException(),
                400,
                'Bad Request',
            ],
            'payload too large' => [
                new PostTooLargeException(),
                413,
                'Payload Too Large',
            ],
            'service unavailable' => [
                new ServiceUnavailableHttpException(),
                503,
                'Service Unavailable',
            ],
            'pdo => 500' => [
                new PDOException('db error'),
                500,
                'Internal Server Error',
            ],
            'invalid argument => 400' => [
                new InvalidArgumentException('Invalid parameter value'),
                400,
                'Bad Request',
            ],
            'logic exception => 400' => [
                new LogicException('Unexpected state'),
                400,
                'Bad Request',
            ],
            'default => 500' => [
                new RuntimeException('unexpected'),
                500,
                'Internal Server Error',
            ],
        ];
    }

    public function test_does_not_overwrite_existing_failure_reason(): void
    {
        Context::add(ApiRequestContext::FAILURE_REASON, 'ip-denied');

        $exception = ValidationException::withMessages(['foo' => ['bar']]);

        $this->renderForApi($exception);

        $this->assertSame('ip-denied', Context::get(ApiRequestContext::FAILURE_REASON));
    }

    public function test_validation_exception_includes_errors_and_uses_422(): void
    {
        $exception = ValidationException::withMessages([
            'email' => ['The email field is required.'],
        ]);

        $response = $this->renderForApi($exception);
        $data = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(422, $data['status']);
        $this->assertSame('Unprocessable Entity', $data['title']);
        $this->assertArrayHasKey('errors', $data);
        $this->assertSame(['The email field is required.'], $data['errors']['email']);
    }

    public function test_method_not_allowed_sets_allow_header(): void
    {
        $exception = new MethodNotAllowedHttpException(['GET', 'POST']);

        $response = $this->renderForApi($exception);
        $data = $response->getData(true);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('Method Not Allowed', $data['title']);
        $this->assertSame('GET, POST', $response->headers->get('Allow'));
    }

    public function test_throttle_requests_uses_retry_after_header(): void
    {
        $exception = new ThrottleRequestsException(
            'Too many requests',
            null,
            ['Retry-After' => 120]
        );

        $response = $this->renderForApi($exception);
        $data = $response->getData(true);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(429, $data['status']);
        $this->assertSame('Too Many Requests', $data['title']);
        $this->assertSame('Too many requests. Please try again later.', $data['detail']);
        $this->assertSame('120', $response->headers->get('Retry-After'));
    }

    public function test_http_exception_interface_fallback_uses_status_and_headers(): void
    {
        $exception = new HttpException(
            418,
            'I am a teapot',
            null,
            ['X-Foo' => 'bar']
        );

        $response = $this->renderForApi($exception);
        $data = $response->getData(true);

        $this->assertSame(418, $response->getStatusCode());
        $this->assertSame(418, $data['status']);
        $this->assertSame('HTTP Error', $data['title']);
        $this->assertSame('I am a teapot', $data['detail']);
        $this->assertSame('bar', $response->headers->get('X-Foo'));
    }

    public function test_unauthorized_includes_www_authenticate_header(): void
    {
        $exception = new AuthenticationException();

        $response = $this->renderForApi($exception);

        $this->assertSame(
            'Bearer realm="Test Realm"',
            $response->headers->get('WWW-Authenticate')
        );
    }

    public function test_unauthorized_http_exception_preserves_www_authenticate_header(): void
    {
        $exception = new UnauthorizedHttpException('Bearer realm="api"');

        $response = $this->renderForApi($exception);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Bearer realm="api"', $response->headers->get('WWW-Authenticate'));
    }

    public function test_non_api_html_request_returns_null(): void
    {
        $exception = new NotFoundHttpException();

        $response = $this->renderForRequest($exception, '/web/page', [
            'HTTP_ACCEPT' => 'text/html',
        ]);

        $this->assertNull($response);
    }

    public function test_non_api_but_json_request_is_still_rendered(): void
    {
        $exception = new NotFoundHttpException();

        $response = $this->renderForRequest($exception, '/non-api/path', [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $this->assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        $this->assertSame(404, $data['status']);
        $this->assertSame('Not Found', $data['title']);
        $this->assertSame('/non-api/path', $data['instance']);
    }

    public function test_auth_realm_resolves_from_api_config_when_not_injected(): void
    {
        // Renderer with no auth realm argument forces resolveAuthRealm() to look
        // up the config value.
        config()->set('api.auth_realm', 'Config Api Realm');
        config()->set('auth.auth_realm');

        $renderer = new ProblemDetailsRenderer();

        $request = Request::create('/api/test', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response = $renderer->render(new AuthenticationException(), $request);

        $this->assertNotNull($response);
        $this->assertSame(
            'Bearer realm="Config Api Realm"',
            $response->headers->get('WWW-Authenticate'),
        );
    }

    public function test_auth_realm_resolves_from_auth_config_when_api_config_missing(): void
    {
        config()->set('api.auth_realm');
        config()->set('auth.auth_realm', 'Auth Config Realm');

        $renderer = new ProblemDetailsRenderer();

        $request = Request::create('/api/test', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response = $renderer->render(new AuthenticationException(), $request);

        $this->assertNotNull($response);
        $this->assertSame(
            'Bearer realm="Auth Config Realm"',
            $response->headers->get('WWW-Authenticate'),
        );
    }

    public function test_auth_realm_falls_back_to_app_name_when_configs_missing(): void
    {
        config()->set('api.auth_realm');
        config()->set('auth.auth_realm');
        config()->set('app.name', 'Chassis App');

        $renderer = new ProblemDetailsRenderer();

        $request = Request::create('/api/test', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response = $renderer->render(new AuthenticationException(), $request);

        $this->assertNotNull($response);
        $this->assertSame(
            'Bearer realm="Chassis App API"',
            $response->headers->get('WWW-Authenticate'),
        );
    }

    public function test_auth_realm_falls_back_to_generic_app_name_when_app_name_not_a_string(): void
    {
        config()->set('api.auth_realm');
        config()->set('auth.auth_realm');
        config()->set('app.name', 12345);

        $renderer = new ProblemDetailsRenderer();

        $request = Request::create('/api/test', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response = $renderer->render(new AuthenticationException(), $request);

        $this->assertNotNull($response);
        $this->assertSame(
            'Bearer realm="App API"',
            $response->headers->get('WWW-Authenticate'),
        );
    }

    public function test_validation_exception_normalizes_non_array_error_messages(): void
    {
        // Build a ValidationException whose errors() returns a scalar value
        // instead of a list for at least one field, exercising the non-array
        // branch in normalizeValidationErrors().
        $validator = \Illuminate\Support\Facades\Validator::make([], []);
        $exception = new ValidationException($validator);

        $reflection = new \ReflectionClass($exception);
        $messages = $reflection->getProperty('response');
        $messages->setAccessible(true);

        // Simulate errors() returning a scalar message per field.
        $messageBag = new class extends \Illuminate\Support\MessageBag
        {
            public function toArray(): array
            {
                return ['field' => 'single-string-not-a-list'];
            }

            public function messages(): array
            {
                return ['field' => 'single-string-not-a-list'];
            }
        };

        $validatorProperty = new \ReflectionProperty($exception, 'validator');
        $validatorProperty->setAccessible(true);
        $originalValidator = $validatorProperty->getValue($exception);

        $validatorMock = new class($messageBag)
        {
            public function __construct(private \Illuminate\Contracts\Support\MessageBag $bag)
            {
            }

            public function errors(): \Illuminate\Contracts\Support\MessageBag
            {
                return $this->bag;
            }
        };

        $validatorProperty->setValue($exception, $validatorMock);

        $response = $this->renderForApi($exception);
        $data = $response->getData(true);

        $this->assertSame(['single-string-not-a-list'], $data['errors']['field']);

        // Restore to avoid leaking into other tests.
        $validatorProperty->setValue($exception, $originalValidator);
    }

    public function test_map_custom_exceptions_hook_overrides_default_match(): void
    {
        $renderer = new class(authRealm: 'Test Realm', apiPrefix: 'api') extends ProblemDetailsRenderer
        {
            protected function mapCustomExceptions(Throwable $e, \Illuminate\Http\Request $request): ?JsonResponse
            {
                if ($e instanceof TokenBudgetExceededException) {
                    $this->setFailure('token-budget-exceeded');

                    return \Northwestern\SysDev\Chassis\Http\Responses\ProblemDetails::response(
                        status: 429,
                        title: 'Token Budget Exceeded',
                        detail: $e->getMessage(),
                    );
                }

                return null;
            }
        };

        $request = Request::create('/api/test', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->app->instance('request', $request);

        $response = $renderer->render(new TokenBudgetExceededException('Monthly cap reached'), $request);

        $this->assertNotNull($response);
        $this->assertSame(429, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertSame('Token Budget Exceeded', $data['title']);
        $this->assertSame('Monthly cap reached', $data['detail']);
        $this->assertSame('token-budget-exceeded', Context::get(ApiRequestContext::FAILURE_REASON));
    }

    public function test_map_custom_exceptions_null_falls_through_to_default_match(): void
    {
        $renderer = new class(authRealm: 'Test Realm', apiPrefix: 'api') extends ProblemDetailsRenderer
        {
            public int $hookCalls = 0;

            protected function mapCustomExceptions(Throwable $e, \Illuminate\Http\Request $request): ?JsonResponse
            {
                $this->hookCalls++;

                // Don't match anything — fall through.
                return null;
            }
        };

        $request = Request::create('/api/test', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->app->instance('request', $request);

        $response = $renderer->render(new NotFoundHttpException(), $request);

        $this->assertNotNull($response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(1, $renderer->hookCalls);
    }

    public function test_map_custom_exceptions_is_not_called_for_non_api_requests(): void
    {
        $renderer = new class(authRealm: 'Test Realm', apiPrefix: 'api') extends ProblemDetailsRenderer
        {
            public int $hookCalls = 0;

            protected function mapCustomExceptions(Throwable $e, \Illuminate\Http\Request $request): ?JsonResponse
            {
                $this->hookCalls++;

                return null;
            }
        };

        $request = Request::create('/web/page', 'GET');
        $this->app->instance('request', $request);

        $response = $renderer->render(new NotFoundHttpException(), $request);

        $this->assertNull($response);
        $this->assertSame(0, $renderer->hookCalls, 'hook should not run for non-API requests');
    }
}

/**
 * Test fixture: a domain exception an app might define.
 */
class TokenBudgetExceededException extends RuntimeException
{
}
