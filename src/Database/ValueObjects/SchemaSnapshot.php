<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Database\ValueObjects;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Represents a snapshot of the database schema state at a point in time.
 *
 * @implements Arrayable<string, mixed>
 */
readonly class SchemaSnapshot implements Arrayable
{
    /**
     * @param  non-empty-string  $name
     * @param  non-empty-string  $checksum
     * @param  non-negative-int  $migrationCount
     * @param  non-negative-int  $seederCount
     */
    public function __construct(
        public string $name,
        public string $checksum,
        public CarbonInterface $createdAt,
        public int $migrationCount,
        public int $seederCount,
    ) {
    }

    /**
     * Create a snapshot instance from array data.
     *
     * @param array{
     *     checksum: string,
     *     created_at: string,
     *     migrations: int,
     *     seeders: int
     * } $data Raw snapshot data from storage
     */
    public static function fromArray(string $name, array $data): self
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Snapshot name cannot be empty.');
        }

        $checksum = $data['checksum'];
        if ($checksum === '') {
            throw new \InvalidArgumentException('Snapshot checksum cannot be empty.');
        }

        return new self(
            name: $name,
            checksum: $checksum,
            createdAt: now()->parse($data['created_at'])->timezone(self::resolveTimezone()),
            migrationCount: max(0, $data['migrations']),
            seederCount: max(0, $data['seeders'])
        );
    }

    /**
     * Convert snapshot to storage format.
     *
     * @return array{
     *     checksum: string,
     *     created_at: string,
     *     migrations: int,
     *     seeders: int
     * }
     */
    public function toArray(): array
    {
        return [
            'checksum' => $this->checksum,
            'created_at' => $this->createdAt->toIso8601String(),
            'migrations' => $this->migrationCount,
            'seeders' => $this->seederCount,
        ];
    }

    /**
     * Resolve the application schedule timezone from config.
     */
    private static function resolveTimezone(): string
    {
        $timezone = config('app.schedule_timezone', 'UTC');

        return is_string($timezone) ? $timezone : 'UTC';
    }

    /**
     * Get a human-readable description of the snapshot.
     */
    public function getDescription(): string
    {
        return sprintf(
            'Snapshot %s (created %s) with %d migrations and %d seeders',
            $this->name,
            $this->createdAt->diffForHumans(),
            $this->migrationCount,
            $this->seederCount
        );
    }
}
