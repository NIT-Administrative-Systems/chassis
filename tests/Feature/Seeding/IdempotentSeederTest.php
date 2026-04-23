<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Seeding;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Northwestern\SysDev\Chassis\Seeding\IdempotentSeeder;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(IdempotentSeeder::class)]
class IdempotentSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('trashables', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_creates_rows_that_do_not_yet_exist(): void
    {
        $seeder = new class extends IdempotentSeeder
        {
            protected string $model = Widget::class;

            protected string $slugColumn = 'slug';

            public function data(): array
            {
                return [
                    ['slug' => 'a', 'label' => 'Alpha', 'sort_order' => 1],
                    ['slug' => 'b', 'label' => 'Beta', 'sort_order' => 2],
                ];
            }
        };

        $seeder->run();

        $this->assertSame(2, Widget::count());
        $this->assertSame('Alpha', Widget::where('slug', 'a')->value('label'));
    }

    public function test_updates_rows_whose_slug_already_exists(): void
    {
        Widget::create(['slug' => 'a', 'label' => 'OLD', 'sort_order' => 99]);

        $seeder = new class extends IdempotentSeeder
        {
            protected string $model = Widget::class;

            protected string $slugColumn = 'slug';

            protected bool $deleteOrphans = false;

            public function data(): array
            {
                return [['slug' => 'a', 'label' => 'Alpha', 'sort_order' => 1]];
            }
        };

        $seeder->run();

        $this->assertSame(1, Widget::count());
        $this->assertSame('Alpha', Widget::where('slug', 'a')->value('label'));
        $this->assertSame(1, Widget::where('slug', 'a')->value('sort_order'));
    }

    public function test_deletes_orphan_rows_by_default(): void
    {
        Widget::create(['slug' => 'keep', 'label' => 'K']);
        Widget::create(['slug' => 'orphan', 'label' => 'O']);

        $seeder = new class extends IdempotentSeeder
        {
            protected string $model = Widget::class;

            protected string $slugColumn = 'slug';

            public function data(): array
            {
                return [['slug' => 'keep', 'label' => 'K']];
            }
        };

        $seeder->run();

        $this->assertSame(1, Widget::count());
        $this->assertNull(Widget::where('slug', 'orphan')->first());
    }

    public function test_preserves_orphans_when_delete_orphans_is_false(): void
    {
        Widget::create(['slug' => 'keep', 'label' => 'K']);
        Widget::create(['slug' => 'orphan', 'label' => 'O']);

        $seeder = new class extends IdempotentSeeder
        {
            protected string $model = Widget::class;

            protected string $slugColumn = 'slug';

            protected bool $deleteOrphans = false;

            public function data(): array
            {
                return [['slug' => 'keep', 'label' => 'K']];
            }
        };

        $seeder->run();

        $this->assertSame(2, Widget::count());
        $this->assertNotNull(Widget::where('slug', 'orphan')->first());
    }

    public function test_restores_soft_deleted_rows_instead_of_duplicating(): void
    {
        $existing = Trashable::create(['slug' => 'a', 'label' => 'Original']);
        $existing->delete();

        $this->assertSoftDeleted($existing);

        $seeder = new class extends IdempotentSeeder
        {
            protected string $model = Trashable::class;

            protected string $slugColumn = 'slug';

            protected bool $deleteOrphans = false;

            public function data(): array
            {
                return [['slug' => 'a', 'label' => 'Restored']];
            }
        };

        $seeder->run();

        $this->assertSame(1, Trashable::count(), 'soft-deleted row should be restored, not duplicated');
        $this->assertSame('Restored', Trashable::where('slug', 'a')->value('label'));
    }

    public function test_strips_transient_keys_before_upsert(): void
    {
        $captured = null;

        $seeder = new class($captured) extends IdempotentSeeder
        {
            protected string $model = Widget::class;

            protected string $slugColumn = 'slug';

            protected array $transient = ['extra'];

            /** @param  array{row: ?array<string, mixed>, model: ?Model}  &$captured */
            public function __construct(protected mixed &$captured)
            {
                $this->captured = ['row' => null, 'model' => null];
            }

            public function data(): array
            {
                return [['slug' => 'a', 'label' => 'Alpha', 'extra' => 'side-data']];
            }

            protected function afterUpsert(Model $model, array $row): void
            {
                $this->captured['row'] = $row;
                $this->captured['model'] = $model;
            }
        };

        $seeder->run();

        // `extra` is not a column on Widget — would throw a QueryException if
        // it leaked into the upsert. Row creation proves it was stripped.
        $this->assertSame(1, Widget::count());

        // The hook still sees the full row, including the transient key.
        $this->assertSame('side-data', $captured['row']['extra']);
        $this->assertSame('a', $captured['model']->slug);
    }

    public function test_after_upsert_hook_fires_for_every_row(): void
    {
        $calls = [];

        $seeder = new class($calls) extends IdempotentSeeder
        {
            protected string $model = Widget::class;

            protected string $slugColumn = 'slug';

            /** @param  array<int, string>  &$calls */
            public function __construct(protected array &$calls)
            {
            }

            public function data(): array
            {
                return [
                    ['slug' => 'a', 'label' => 'A'],
                    ['slug' => 'b', 'label' => 'B'],
                ];
            }

            protected function afterUpsert(Model $model, array $row): void
            {
                /** @var Widget $model */
                $this->calls[] = $model->slug;
            }
        };

        $seeder->run();

        $this->assertSame(['a', 'b'], $calls);
    }
}

class Widget extends Model
{
    protected $table = 'widgets';

    protected $guarded = [];
}

class Trashable extends Model
{
    use SoftDeletes;

    protected $table = 'trashables';

    protected $guarded = [];
}
