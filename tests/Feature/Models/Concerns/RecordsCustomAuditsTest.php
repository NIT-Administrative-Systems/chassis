<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Northwestern\SysDev\Chassis\Models\Concerns\Auditable;
use Northwestern\SysDev\Chassis\Models\Concerns\RecordsCustomAudits;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use OwenIt\Auditing\AuditingServiceProvider;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Models\Audit;
use PHPUnit\Framework\Attributes\CoversTrait;

#[CoversTrait(RecordsCustomAudits::class)]
class RecordsCustomAuditsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            AuditingServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('auth.providers.users.model', CustomAuditTestUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Set after Laravel Auditing merges its defaults, which replace any top-level key set earlier.
        config(['audit.console' => true, 'audit.user.guards' => ['web']]);

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('custom_audit_test_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('revoked')->default(false);
            $table->timestamps();
        });

        Schema::create('audits', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('user');
            $table->string('event');
            $table->morphs('auditable');
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->text('url')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 1023)->nullable();
            $table->string('tags')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_records_the_event_with_its_values_and_the_signed_in_user(): void
    {
        $user = CustomAuditTestUser::query()->create(['name' => 'Willie Wildcat']);
        $credential = CustomAuditTestCredential::query()->create(['name' => 'Nightly sync']);
        Audit::query()->delete();

        $this->actingAs($user);

        $credential->recordCustomAudit('revoked', new: ['revoked' => true], old: ['revoked' => false]);

        $audit = Audit::query()->sole();

        $this->assertSame('revoked', $audit->getAttribute('event'));
        $this->assertTrue($audit->getAttribute('auditable')?->is($credential));
        $this->assertTrue($audit->getAttribute('user')?->is($user));
        $this->assertSame(['revoked' => false], $audit->getAttribute('old_values'));
        $this->assertSame(['revoked' => true], $audit->getAttribute('new_values'));
    }

    public function test_the_old_values_default_to_none(): void
    {
        $credential = CustomAuditTestCredential::query()->create(['name' => 'Nightly sync']);
        Audit::query()->delete();

        $credential->recordCustomAudit('rotated', ['secret' => 'rotated']);

        $audit = Audit::query()->sole();

        $this->assertSame('rotated', $audit->getAttribute('event'));
        $this->assertSame([], $audit->getAttribute('old_values'));
        $this->assertSame(['secret' => 'rotated'], $audit->getAttribute('new_values'));
        $this->assertNull($audit->getAttribute('user_id'));
    }

    // The event's values belong to it alone, not to the model's next audit.
    public function test_the_next_save_is_audited_as_itself(): void
    {
        $credential = CustomAuditTestCredential::query()->create(['name' => 'Nightly sync']);
        Audit::query()->delete();

        $credential->recordCustomAudit('revoked', new: ['revoked' => true], old: ['revoked' => false]);
        $credential->update(['name' => 'Weekly sync']);

        $audit = Audit::query()->orderByDesc('id')->firstOrFail();

        $this->assertSame('updated', $audit->getAttribute('event'));
        $this->assertSame(['name' => 'Nightly sync'], $audit->getAttribute('old_values'));
        $this->assertSame(['name' => 'Weekly sync'], $audit->getAttribute('new_values'));
    }
}

class CustomAuditTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}

class CustomAuditTestCredential extends Model implements AuditableContract
{
    use Auditable;
    use RecordsCustomAudits;

    protected $table = 'custom_audit_test_credentials';

    protected $guarded = [];
}
