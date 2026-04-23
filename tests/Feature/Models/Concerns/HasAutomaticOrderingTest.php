<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Northwestern\SysDev\Chassis\Attributes\AutomaticallyOrdered;
use Northwestern\SysDev\Chassis\Models\Concerns\HasAutomaticOrdering;
use Northwestern\SysDev\Chassis\Models\Scopes\AutomaticallyOrderedScope;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversTrait;

#[CoversTrait(HasAutomaticOrdering::class)]
class HasAutomaticOrderingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('trait_test_widgets', function (Blueprint $table): void {
            $table->id();
            $table->integer('order_index');
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('trait_test_users', function (Blueprint $table): void {
            $table->id();
            $table->integer('order_index');
            $table->string('label');
            $table->timestamps();
        });
    }

    public function test_trait_registers_scope_on_plain_model(): void
    {
        TraitTestWidget::create(['order_index' => 3, 'label' => 'C']);
        TraitTestWidget::create(['order_index' => 1, 'label' => 'B']);
        TraitTestWidget::create(['order_index' => 1, 'label' => 'A']);

        $widgets = TraitTestWidget::all();

        $this->assertSame('A', $widgets[0]->label);
        $this->assertSame('B', $widgets[1]->label);
        $this->assertSame('C', $widgets[2]->label);
    }

    public function test_trait_registers_scope_on_authenticatable(): void
    {
        TraitTestUser::create(['order_index' => 3, 'label' => 'C']);
        TraitTestUser::create(['order_index' => 1, 'label' => 'A']);
        TraitTestUser::create(['order_index' => 2, 'label' => 'B']);

        $users = TraitTestUser::all();

        $this->assertSame('A', $users[0]->label);
        $this->assertSame('B', $users[1]->label);
        $this->assertSame('C', $users[2]->label);
    }

    public function test_scope_can_be_disabled_per_query(): void
    {
        TraitTestWidget::create(['order_index' => 3, 'label' => 'C']);
        TraitTestWidget::create(['order_index' => 1, 'label' => 'A']);

        $widgets = TraitTestWidget::withoutGlobalScope(AutomaticallyOrderedScope::class)->get();

        $this->assertSame('C', $widgets[0]->label);
        $this->assertSame('A', $widgets[1]->label);
    }

    public function test_trait_is_noop_when_no_attribute_present(): void
    {
        TraitTestUnordered::create(['order_index' => 3, 'label' => 'C']);
        TraitTestUnordered::create(['order_index' => 1, 'label' => 'A']);

        $rows = TraitTestUnordered::all();

        // Insertion order preserved because no scope was registered.
        $this->assertSame('C', $rows[0]->label);
        $this->assertSame('A', $rows[1]->label);
    }
}

/**
 * @property int $id
 * @property int $order_index
 * @property string $label
 */
#[AutomaticallyOrdered]
class TraitTestWidget extends Model
{
    use HasAutomaticOrdering;

    protected $table = 'trait_test_widgets';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * @property int $id
 * @property int $order_index
 * @property string $label
 */
#[AutomaticallyOrdered]
class TraitTestUser extends Authenticatable
{
    use HasAutomaticOrdering;

    protected $table = 'trait_test_users';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * @property int $id
 * @property int $order_index
 * @property string $label
 */
class TraitTestUnordered extends Model
{
    use HasAutomaticOrdering;

    protected $table = 'trait_test_widgets';

    protected $guarded = [];

    public $timestamps = false;
}
