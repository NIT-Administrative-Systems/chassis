<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Seeding\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Northwestern\SysDev\Chassis\Seeding\Concerns\CleansUpOrphans;
use Northwestern\SysDev\Chassis\Seeding\Concerns\PerformsIdempotentUpserts;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversTrait;

/**
 * Exercises `PerformsIdempotentUpserts` and `CleansUpOrphans` as standalone
 * traits — the "bespoke seeder" path where a class picks individual helpers
 * instead of extending `IdempotentSeeder`.
 */
#[CoversTrait(PerformsIdempotentUpserts::class)]
#[CoversTrait(CleansUpOrphans::class)]
class PerformsIdempotentUpsertsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('bespoke_widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('kind');
            $table->string('region');
            $table->string('label');
            $table->unique(['kind', 'region']);
            $table->timestamps();
        });

        Schema::create('bespoke_trashables', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_upsert_by_composite_key_on_a_bespoke_seeder(): void
    {
        $seeder = new class
        {
            use PerformsIdempotentUpserts;

            public function seed(): void
            {
                $this->upsertByCompositeKey(
                    BespokeWidget::class,
                    ['kind' => 'sensor', 'region' => 'us-east', 'label' => 'Primary'],
                    ['kind', 'region'],
                );
                $this->upsertByCompositeKey(
                    BespokeWidget::class,
                    ['kind' => 'sensor', 'region' => 'us-east', 'label' => 'Updated'],
                    ['kind', 'region'],
                );
            }
        };

        $seeder->seed();

        $this->assertSame(1, BespokeWidget::count(), 'composite-key match should update, not duplicate');
        $this->assertSame('Updated', BespokeWidget::first()->label);
    }

    public function test_upsert_by_slug_restores_soft_deleted_row(): void
    {
        $existing = BespokeTrashable::create(['slug' => 'a', 'label' => 'Original']);
        $existing->delete();

        $seeder = new class
        {
            use PerformsIdempotentUpserts;

            public function seed(): void
            {
                $this->upsertBySlug(
                    BespokeTrashable::class,
                    ['slug' => 'a', 'label' => 'Revived'],
                    'slug',
                );
            }
        };

        $seeder->seed();

        $this->assertSame(1, BespokeTrashable::count());
        $this->assertSame('Revived', BespokeTrashable::first()->label);
    }

    public function test_clean_up_orphans_removes_missing_rows(): void
    {
        BespokeTrashable::create(['slug' => 'keep', 'label' => 'K']);
        BespokeTrashable::create(['slug' => 'orphan', 'label' => 'O']);

        $seeder = new class
        {
            use CleansUpOrphans;

            public function prune(): int
            {
                return $this->deleteOrphans(BespokeTrashable::class, 'slug', ['keep']);
            }
        };

        $deleted = $seeder->prune();

        $this->assertSame(1, $deleted);
        // SoftDeletes: orphan is soft-deleted, not hard-deleted.
        $this->assertSoftDeleted('bespoke_trashables', ['slug' => 'orphan']);
        $this->assertSame(1, BespokeTrashable::count());
    }
}

class BespokeWidget extends Model
{
    protected $table = 'bespoke_widgets';

    protected $guarded = [];
}

class BespokeTrashable extends Model
{
    use SoftDeletes;

    protected $table = 'bespoke_trashables';

    protected $guarded = [];
}
