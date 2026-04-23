<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Attributes;

use Carbon\CarbonInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Northwestern\SysDev\Chassis\Attributes\AutomaticallyOrdered;
use Northwestern\SysDev\Chassis\Models\BaseModel;
use Northwestern\SysDev\Chassis\Models\Scopes\AutomaticallyOrderedScope;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AutomaticallyOrdered::class)]
#[CoversClass(AutomaticallyOrderedScope::class)]
class AutomaticallyOrderedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('test_categories', function (Blueprint $table) {
            $table->id();
            $table->integer('order_index');
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('test_products', function (Blueprint $table) {
            $table->id();
            $table->integer('sort_order');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('test_items_without_label', function (Blueprint $table) {
            $table->id();
            $table->integer('order_index');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('test_items_without_order_index', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('test_items_without_any_columns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('test_articles', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at');
            $table->string('title');
        });

        Schema::create('test_tasks', function (Blueprint $table) {
            $table->id();
            $table->integer('priority');
            $table->timestamp('due_date');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_categories');
        Schema::dropIfExists('test_products');
        Schema::dropIfExists('test_items_without_label');
        Schema::dropIfExists('test_items_without_order_index');
        Schema::dropIfExists('test_items_without_any_columns');
        Schema::dropIfExists('test_articles');
        Schema::dropIfExists('test_tasks');

        parent::tearDown();
    }

    public function test_it_can_be_instantiated_with_default_values(): void
    {
        $attribute = new AutomaticallyOrdered();

        $this->assertSame('order_index', $attribute->primary);
        $this->assertSame('asc', $attribute->primaryDirection);
        $this->assertSame('label', $attribute->secondary);
        $this->assertSame('asc', $attribute->secondaryDirection);
    }

    public function test_it_can_be_instantiated_with_custom_values(): void
    {
        $attribute = new AutomaticallyOrdered(
            primary: 'sort_order',
            secondary: 'name'
        );

        $this->assertSame('sort_order', $attribute->primary);
        $this->assertSame('asc', $attribute->primaryDirection);
        $this->assertSame('name', $attribute->secondary);
        $this->assertSame('asc', $attribute->secondaryDirection);
    }

    public function test_it_can_be_instantiated_with_custom_directions(): void
    {
        $attribute = new AutomaticallyOrdered(
            primary: 'created_at',
            primaryDirection: 'desc',
            secondary: 'title',
            secondaryDirection: 'asc'
        );

        $this->assertSame('created_at', $attribute->primary);
        $this->assertSame('desc', $attribute->primaryDirection);
        $this->assertSame('title', $attribute->secondary);
        $this->assertSame('asc', $attribute->secondaryDirection);
    }

    public function test_models_with_default_attribute_are_automatically_ordered(): void
    {
        TestCategory::create(['order_index' => 3, 'label' => 'C']);
        TestCategory::create(['order_index' => 1, 'label' => 'B']);
        TestCategory::create(['order_index' => 1, 'label' => 'A']);
        TestCategory::create(['order_index' => 2, 'label' => 'D']);

        $categories = TestCategory::all();

        $this->assertSame('A', $categories[0]->label);
        $this->assertSame('B', $categories[1]->label);
        $this->assertSame('D', $categories[2]->label);
        $this->assertSame('C', $categories[3]->label);
    }

    public function test_models_with_custom_attribute_are_ordered_by_custom_columns(): void
    {
        TestProduct::create(['sort_order' => 3, 'name' => 'Product C']);
        TestProduct::create(['sort_order' => 1, 'name' => 'Product B']);
        TestProduct::create(['sort_order' => 1, 'name' => 'Product A']);
        TestProduct::create(['sort_order' => 2, 'name' => 'Product D']);

        $products = TestProduct::all();

        $this->assertSame('Product A', $products[0]->name);
        $this->assertSame('Product B', $products[1]->name);
        $this->assertSame('Product D', $products[2]->name);
        $this->assertSame('Product C', $products[3]->name);
    }

    public function test_automatic_ordering_can_be_disabled_for_single_query(): void
    {
        TestCategory::create(['order_index' => 3, 'label' => 'C']);
        TestCategory::create(['order_index' => 1, 'label' => 'B']);
        TestCategory::create(['order_index' => 2, 'label' => 'A']);

        $categories = TestCategory::withoutGlobalScope(AutomaticallyOrderedScope::class)->get();

        $this->assertSame('C', $categories[0]->label);
        $this->assertSame('B', $categories[1]->label);
        $this->assertSame('A', $categories[2]->label);
    }

    public function test_custom_ordering_can_be_disabled_for_single_query(): void
    {
        TestProduct::create(['sort_order' => 3, 'name' => 'Product C']);
        TestProduct::create(['sort_order' => 1, 'name' => 'Product B']);
        TestProduct::create(['sort_order' => 2, 'name' => 'Product A']);

        $products = TestProduct::withoutGlobalScope(AutomaticallyOrderedScope::class)->get();

        $this->assertSame('Product C', $products[0]->name);
        $this->assertSame('Product B', $products[1]->name);
        $this->assertSame('Product A', $products[2]->name);
    }

    public function test_ordering_persists_across_multiple_queries(): void
    {
        TestCategory::create(['order_index' => 2, 'label' => 'B']);
        TestCategory::create(['order_index' => 1, 'label' => 'A']);

        $firstQuery = TestCategory::all();
        $secondQuery = TestCategory::all();

        $this->assertSame('A', $firstQuery[0]->label);
        $this->assertSame('A', $secondQuery[0]->label);
    }

    public function test_ordering_works_when_child_model_overrides_booted_method(): void
    {
        TestItemWithCustomBooted::create(['order_index' => 3, 'label' => 'C']);
        TestItemWithCustomBooted::create(['order_index' => 1, 'label' => 'B']);
        TestItemWithCustomBooted::create(['order_index' => 1, 'label' => 'A']);

        $items = TestItemWithCustomBooted::all();

        $this->assertSame('A', $items[0]->label);
        $this->assertSame('B', $items[1]->label);
        $this->assertSame('C', $items[2]->label);

        $this->assertTrue(TestItemWithCustomBooted::$customBootedCalled);
    }

    public function test_ordering_skips_missing_secondary_column(): void
    {
        TestItemWithoutLabel::create(['order_index' => 3, 'name' => 'Third']);
        TestItemWithoutLabel::create(['order_index' => 1, 'name' => 'First']);
        TestItemWithoutLabel::create(['order_index' => 2, 'name' => 'Second']);

        $items = TestItemWithoutLabel::all();

        $this->assertSame('First', $items[0]->name);
        $this->assertSame('Second', $items[1]->name);
        $this->assertSame('Third', $items[2]->name);
    }

    public function test_ordering_skips_missing_primary_column(): void
    {
        TestItemWithoutOrderIndex::create(['label' => 'C']);
        TestItemWithoutOrderIndex::create(['label' => 'A']);
        TestItemWithoutOrderIndex::create(['label' => 'B']);

        $items = TestItemWithoutOrderIndex::all();

        $this->assertSame('A', $items[0]->label);
        $this->assertSame('B', $items[1]->label);
        $this->assertSame('C', $items[2]->label);
    }

    public function test_attribute_does_not_break_when_no_columns_exist(): void
    {
        TestItemWithoutAnyColumns::create(['name' => 'First']);
        TestItemWithoutAnyColumns::create(['name' => 'Second']);
        TestItemWithoutAnyColumns::create(['name' => 'Third']);

        $items = TestItemWithoutAnyColumns::all();

        $this->assertCount(3, $items);
        $this->assertSame('First', $items[0]->name);
        $this->assertSame('Second', $items[1]->name);
        $this->assertSame('Third', $items[2]->name);
    }

    public function test_models_can_order_by_primary_column_descending(): void
    {
        $now = now();
        TestArticle::create(['created_at' => $now->copy()->subDays(3), 'title' => 'Oldest']);
        TestArticle::create(['created_at' => $now->copy()->subDays(1), 'title' => 'Newest']);
        TestArticle::create(['created_at' => $now->copy()->subDays(2), 'title' => 'Middle']);

        $articles = TestArticle::all();

        $this->assertSame('Newest', $articles[0]->title);
        $this->assertSame('Middle', $articles[1]->title);
        $this->assertSame('Oldest', $articles[2]->title);
    }

    public function test_models_can_order_by_both_columns_descending(): void
    {
        $now = now();
        TestTask::create(['priority' => 1, 'due_date' => $now->copy()->addDays(1)]);
        TestTask::create(['priority' => 3, 'due_date' => $now->copy()->addDays(3)]);
        TestTask::create(['priority' => 3, 'due_date' => $now->copy()->addDays(1)]);
        TestTask::create(['priority' => 2, 'due_date' => $now->copy()->addDays(2)]);

        $tasks = TestTask::all();

        $this->assertSame(3, $tasks[0]->priority);
        $this->assertSame($now->copy()->addDays(3)->timestamp, $tasks[0]->due_date->timestamp);
        $this->assertSame(3, $tasks[1]->priority);
        $this->assertSame($now->copy()->addDays(1)->timestamp, $tasks[1]->due_date->timestamp);
        $this->assertSame(2, $tasks[2]->priority);
        $this->assertSame(1, $tasks[3]->priority);
    }
}

/**
 * @property int $id
 * @property int $order_index
 * @property string $label
 */
#[AutomaticallyOrdered]
class TestCategory extends BaseModel
{
    protected $table = 'test_categories';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * @property int $id
 * @property int $sort_order
 * @property string $name
 */
#[AutomaticallyOrdered(primary: 'sort_order', secondary: 'name')]
class TestProduct extends BaseModel
{
    protected $table = 'test_products';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * @property int $id
 * @property int $order_index
 * @property string $label
 */
#[AutomaticallyOrdered]
class TestItemWithCustomBooted extends BaseModel
{
    protected $table = 'test_categories';

    protected $guarded = [];

    public $timestamps = false;

    public static bool $customBootedCalled = false;

    protected static function booted(): void
    {
        parent::booted();

        static::$customBootedCalled = true;
    }
}

/**
 * @property int $id
 * @property int $order_index
 * @property string $name
 */
#[AutomaticallyOrdered]
class TestItemWithoutLabel extends BaseModel
{
    protected $table = 'test_items_without_label';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * @property int $id
 * @property string $label
 */
#[AutomaticallyOrdered]
class TestItemWithoutOrderIndex extends BaseModel
{
    protected $table = 'test_items_without_order_index';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * @property int $id
 * @property string $name
 */
#[AutomaticallyOrdered]
class TestItemWithoutAnyColumns extends BaseModel
{
    protected $table = 'test_items_without_any_columns';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * @property int $id
 * @property Carbon $created_at
 * @property string $title
 */
#[AutomaticallyOrdered(primary: 'created_at', primaryDirection: 'desc', secondary: 'title')]
class TestArticle extends BaseModel
{
    protected $table = 'test_articles';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
    ];
}

/**
 * @property int $id
 * @property int $priority
 * @property CarbonInterface $due_date
 */
#[AutomaticallyOrdered(primary: 'priority', primaryDirection: 'desc', secondary: 'due_date', secondaryDirection: 'desc')]
class TestTask extends BaseModel
{
    protected $table = 'test_tasks';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'due_date' => 'datetime',
    ];
}
