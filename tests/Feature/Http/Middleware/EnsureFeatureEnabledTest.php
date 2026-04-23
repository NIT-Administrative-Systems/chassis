<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Http\Middleware;

use Illuminate\Support\Facades\Route;
use Northwestern\SysDev\Chassis\Http\Middleware\EnsureFeatureEnabled;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(EnsureFeatureEnabled::class)]
class EnsureFeatureEnabledTest extends TestCase
{
    private string $endpoint = '/api/feature-enabled-test';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware([EnsureFeatureEnabled::class . ':test.feature_enabled'])
            ->get($this->endpoint, function () {
                return response()->json(['ok' => true]);
            });
    }

    public function test_request_is_blocked_with_503_when_feature_is_disabled(): void
    {
        config(['test.feature_enabled' => false]);

        $this->getJson($this->endpoint)->assertServiceUnavailable();
    }

    public function test_request_passes_through_when_feature_is_enabled(): void
    {
        config(['test.feature_enabled' => true]);

        $this->getJson($this->endpoint)
            ->assertOk()
            ->assertJson(['ok' => true]);
    }
}
