<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Seeding\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use InvalidArgumentException;
use Northwestern\SysDev\Chassis\Models\BaseModel;
use Northwestern\SysDev\Chassis\Seeding\Concerns\AuditsSeederChanges;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversTrait;
use ReflectionClass;

// --- Test fixtures ---

class AuditTestSeeder extends Seeder
{
    use AuditsSeederChanges;

    /**
     * @param  list<class-string>  $models
     */
    public function callWithAuditing(array $models, callable $callback): mixed
    {
        return $this->withAuditing($models, $callback);
    }
}

/**
 * A model that implements the Auditable contract for testing.
 */
class AuditTestModel extends BaseModel
{
    protected $table = 'audit_test_models';
}

/**
 * A plain Eloquent model that does NOT implement the Auditable contract.
 */
class NonAuditableTestModel extends Model
{
}

#[CoversTrait(AuditsSeederChanges::class)]
class AuditsSeederChangesTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->resetObservedModels();

        parent::tearDown();
    }

    private function resetObservedModels(): void
    {
        $property = (new ReflectionClass(AuditTestSeeder::class))->getProperty('observedModels');
        $property->setValue(null, []);
    }

    private function getObservedModels(): array
    {
        $property = (new ReflectionClass(AuditTestSeeder::class))->getProperty('observedModels');

        return $property->getValue();
    }

    private function withProductionEnvironment(callable $callback): void
    {
        app()->detectEnvironment(fn () => 'production');

        try {
            $callback();
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_with_auditing_returns_callback_value(): void
    {
        $seeder = new AuditTestSeeder();

        $this->assertSame('seeded', $seeder->callWithAuditing([AuditTestModel::class], fn () => 'seeded'));
    }

    public function test_with_auditing_returns_null_from_void_callback(): void
    {
        $seeder = new AuditTestSeeder();

        $this->assertNull($seeder->callWithAuditing([AuditTestModel::class], function () {
        }));
    }

    public function test_with_auditing_throws_for_non_auditable_model(): void
    {
        $seeder = new AuditTestSeeder();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement');

        $seeder->callWithAuditing([NonAuditableTestModel::class], fn () => null);
    }

    public function test_with_auditing_validates_all_models_before_registering_any(): void
    {
        $seeder = new AuditTestSeeder();

        $this->expectException(InvalidArgumentException::class);

        $seeder->callWithAuditing([AuditTestModel::class, NonAuditableTestModel::class], fn () => null);
    }

    public function test_with_auditing_skips_observer_registration_in_testing_environment(): void
    {
        $seeder = new AuditTestSeeder();
        $seeder->callWithAuditing([AuditTestModel::class], fn () => null);

        $this->assertEmpty($this->getObservedModels());
    }

    public function test_with_auditing_executes_callback_in_testing_environment(): void
    {
        $seeder = new AuditTestSeeder();
        $executed = false;

        $seeder->callWithAuditing([AuditTestModel::class], function () use (&$executed) {
            $executed = true;
        });

        $this->assertTrue($executed);
    }

    public function test_with_auditing_registers_observer_in_non_testing_environment(): void
    {
        $seeder = new AuditTestSeeder();

        $this->withProductionEnvironment(function () use ($seeder) {
            $seeder->callWithAuditing([AuditTestModel::class], fn () => null);
        });

        $observed = $this->getObservedModels();
        $this->assertArrayHasKey(AuditTestModel::class, $observed);
    }

    public function test_register_observer_once_prevents_duplicate_registration(): void
    {
        $seeder = new AuditTestSeeder();

        $this->withProductionEnvironment(function () use ($seeder) {
            $seeder->callWithAuditing([AuditTestModel::class], fn () => null);
        });

        $this->assertCount(1, $this->getObservedModels());

        $this->withProductionEnvironment(function () use ($seeder) {
            $seeder->callWithAuditing([AuditTestModel::class], fn () => null);
        });

        $this->assertCount(1, $this->getObservedModels());
    }

    public function test_with_auditing_accepts_empty_model_list(): void
    {
        $seeder = new AuditTestSeeder();

        $this->assertSame('ok', $seeder->callWithAuditing([], fn () => 'ok'));
    }
}
