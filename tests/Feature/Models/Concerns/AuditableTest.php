<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Models\Concerns;

use Illuminate\Support\Facades\Context;
use Livewire\LivewireServiceProvider;
use Northwestern\SysDev\Chassis\Models\Concerns\Auditable;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use Northwestern\SysDev\Chassis\ValueObjects\ApiRequestContext;
use PHPUnit\Framework\Attributes\CoversTrait;

#[CoversTrait(Auditable::class)]
class AuditableTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            LivewireServiceProvider::class,
        ];
    }

    protected \Illuminate\Database\Eloquent\Model $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = new class extends \Illuminate\Database\Eloquent\Model implements \OwenIt\Auditing\Contracts\Auditable
        {
            use Auditable;

            protected $table = 'test_models';

            protected $guarded = [];

            /** @var list<string> */
            public array $auditCustomTags = [];

            /** @var array<string, mixed> */
            public array $auditCustomContext = [];
        };
    }

    public function test_attaches_trace_id_from_context(): void
    {
        Context::add(ApiRequestContext::TRACE_ID, 'abc-123');

        $data = $this->model->transformAudit(['event' => 'created']);

        $this->assertSame('abc-123', $data['trace_id']);
    }

    public function test_does_not_attach_trace_id_when_not_in_context(): void
    {
        Context::flush();

        $data = $this->model->transformAudit(['event' => 'created']);

        $this->assertArrayNotHasKey('trace_id', $data);
    }

    public function test_handles_missing_impersonate_binding_gracefully(): void
    {
        // impersonate is not bound — should not throw
        $data = $this->model->transformAudit(['event' => 'updated']);

        $this->assertArrayNotHasKey('impersonator_user_id', $data);
    }

    public function test_attaches_impersonator_id_when_impersonate_is_bound(): void
    {
        $impersonate = new class
        {
            public function getImpersonatorId(): int
            {
                return 42;
            }
        };

        app()->instance('impersonate', $impersonate);

        $data = $this->model->transformAudit(['event' => 'updated']);

        $this->assertSame(42, $data['impersonator_user_id']);
    }

    public function test_handles_missing_livewire_gracefully(): void
    {
        // Livewire class does not exist in the test environment — should not throw
        $data = $this->model->transformAudit(['event' => 'created', 'url' => 'https://example.com/dashboard']);

        $this->assertSame('https://example.com/dashboard', $data['url']);
    }

    public function test_appends_livewire_component_name_to_url_when_snapshot_is_present(): void
    {
        request()->replace([
            'components' => [
                ['snapshot' => json_encode(['memo' => ['name' => 'users.table']])],
            ],
        ]);

        $updateUri = \Livewire\Livewire::getUpdateUri();

        $data = $this->model->transformAudit([
            'event' => 'updated',
            'url' => $updateUri,
        ]);

        $this->assertSame($updateUri . '#users.table', $data['url']);
    }

    public function test_does_not_append_component_when_url_is_not_a_livewire_request(): void
    {
        request()->replace([
            'components' => [
                ['snapshot' => json_encode(['memo' => ['name' => 'users.table']])],
            ],
        ]);

        $data = $this->model->transformAudit([
            'event' => 'updated',
            'url' => 'https://example.com/dashboard',
        ]);

        $this->assertSame('https://example.com/dashboard', $data['url']);
    }

    public function test_does_not_append_component_when_livewire_url_but_no_snapshot(): void
    {
        request()->replace([
            'components' => [
                ['other_key' => 'value'],
            ],
        ]);

        $updateUri = \Livewire\Livewire::getUpdateUri();

        $data = $this->model->transformAudit([
            'event' => 'updated',
            'url' => $updateUri,
        ]);

        $this->assertSame($updateUri, $data['url']);
    }

    public function test_handles_invalid_livewire_snapshot_json_without_error(): void
    {
        request()->replace([
            'components' => [
                ['snapshot' => '{invalid-json'],
            ],
        ]);

        $updateUri = \Livewire\Livewire::getUpdateUri();

        $data = $this->model->transformAudit([
            'event' => 'updated',
            'url' => $updateUri,
        ]);

        $this->assertSame($updateUri, $data['url']);
    }

    public function test_snapshot_memo_name_that_is_not_a_string_is_ignored(): void
    {
        request()->replace([
            'components' => [
                ['snapshot' => json_encode(['memo' => ['name' => 42]])],
            ],
        ]);

        $updateUri = \Livewire\Livewire::getUpdateUri();

        $data = $this->model->transformAudit([
            'event' => 'updated',
            'url' => $updateUri,
        ]);

        $this->assertSame($updateUri, $data['url']);
    }

    public function test_catches_throwable_from_livewire_integration(): void
    {
        // Model with an extractLivewireComponentName() override that throws to
        // exercise the catch-all in transformAudit() (e.g. Livewire installed
        // but not booted, URL generator errors, etc.).
        $model = new class extends \Illuminate\Database\Eloquent\Model implements \OwenIt\Auditing\Contracts\Auditable
        {
            use Auditable;

            protected $table = 'test_models';

            protected $guarded = [];

            protected function extractLivewireComponentName(): ?string
            {
                throw new \RuntimeException('boom');
            }
        };

        $updateUri = \Livewire\Livewire::getUpdateUri();

        // Feed a matching URL so the code enters the Str::contains() branch and
        // calls extractLivewireComponentName(), which throws and is swallowed.
        $data = $model->transformAudit([
            'event' => 'updated',
            'url' => $updateUri,
        ]);

        // URL is unchanged because the throw short-circuited the append logic.
        $this->assertSame($updateUri, $data['url']);
    }

    protected function tearDown(): void
    {
        request()->replace([]);

        parent::tearDown();
    }

    public function test_merges_custom_tags(): void
    {
        $this->model->auditCustomTags = ['role-change'];

        $data = $this->model->transformAudit(['event' => 'updated', 'tags' => null]);

        $this->assertSame('role-change', $data['tags']);
    }

    public function test_merges_custom_tags_with_existing_tags(): void
    {
        $this->model->auditCustomTags = ['role-change'];

        $data = $this->model->transformAudit(['event' => 'updated', 'tags' => 'existing-tag']);

        $this->assertSame('existing-tag,role-change', $data['tags']);
    }

    public function test_merges_custom_context_into_tags(): void
    {
        $this->model->auditCustomTags = ['role-change'];
        $this->model->auditCustomContext = ['origin' => 'admin-panel'];

        $data = $this->model->transformAudit(['event' => 'updated', 'tags' => null]);

        $this->assertSame('role-change,origin: admin-panel', $data['tags']);
    }
}
