<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Northwestern\SysDev\Chassis\Models\Concerns\PrunesAfterRetentionPeriod;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversTrait(PrunesAfterRetentionPeriod::class)]
class PrunesAfterRetentionPeriodTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('retention_test_records', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('logged_in_at');
            $table->timestamps();
        });
    }

    public function test_records_older_than_the_retention_period_are_pruned(): void
    {
        config(['retention.login_records' => 365]);

        $old = RetentionTestLoginRecord::query()->create(['logged_in_at' => now()->subDays(400)]);
        $recent = RetentionTestLoginRecord::query()->create(['logged_in_at' => now()->subDays(10)]);

        (new RetentionTestLoginRecord())->pruneAll();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    public function test_a_numeric_string_from_the_environment_is_accepted(): void
    {
        config(['retention.login_records' => '365']);

        $old = RetentionTestLoginRecord::query()->create(['logged_in_at' => now()->subDays(400)]);

        (new RetentionTestLoginRecord())->pruneAll();

        $this->assertModelMissing($old);
    }

    public function test_a_null_or_empty_setting_keeps_every_record(): void
    {
        $old = RetentionTestLoginRecord::query()->create(['logged_in_at' => now()->subYears(10)]);

        foreach ([null, ''] as $setting) {
            config(['retention.login_records' => $setting]);

            (new RetentionTestLoginRecord())->pruneAll();

            $this->assertModelExists($old);
        }
    }

    #[DataProvider('invalidSettings')]
    public function test_an_invalid_setting_is_rejected(mixed $setting): void
    {
        config(['retention.login_records' => $setting]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('[retention.login_records] must be a number of days (0 or more) or null.');

        (new RetentionTestLoginRecord())->prunable();
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidSettings(): array
    {
        return [
            'negative' => [-1],
            'not a number' => ['forever'],
        ];
    }

    public function test_the_retention_period_counts_from_created_at_by_default(): void
    {
        config(['retention.audits' => null]);
        $this->assertStringContainsString('1 = 0', (new RetentionTestAudit())->prunable()->toSql());

        config(['retention.audits' => 30]);
        $this->assertStringContainsString('"created_at" <', (new RetentionTestAudit())->prunable()->toSql());
    }
}

class RetentionTestLoginRecord extends Model
{
    use PrunesAfterRetentionPeriod;

    protected $table = 'retention_test_records';

    protected $guarded = [];

    protected function retentionConfigKey(): string
    {
        return 'retention.login_records';
    }

    protected function retentionColumn(): string
    {
        return 'logged_in_at';
    }
}

class RetentionTestAudit extends Model
{
    use PrunesAfterRetentionPeriod;

    protected $table = 'retention_test_records';

    protected function retentionConfigKey(): string
    {
        return 'retention.audits';
    }
}
