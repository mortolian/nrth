<?php

namespace Database\Factories;

use App\Domain\Backup\Enums\InstanceBackupRunStatus;
use App\Domain\Backup\Models\InstanceBackupRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstanceBackupRun>
 */
class InstanceBackupRunFactory extends Factory
{
    protected $model = InstanceBackupRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requested_by' => User::factory(),
            'status' => InstanceBackupRunStatus::Queued,
            'types' => ['daily'],
            'filename' => null,
            'disk' => null,
            'storage_path' => null,
            'file_size_bytes' => null,
            'error_message' => null,
            'completed_at' => null,
        ];
    }

    public function ready(): static
    {
        $filename = now()->format('Y-m-d-H-i-s').'.zip';

        return $this->state(fn (): array => [
            'status' => InstanceBackupRunStatus::Ready,
            'types' => ['daily'],
            'filename' => $filename,
            'disk' => 'local',
            'storage_path' => 'nrth/'.$filename,
            'file_size_bytes' => 1024,
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => InstanceBackupRunStatus::Failed,
            'error_message' => 'Backup failed in test.',
            'completed_at' => now(),
        ]);
    }
}
