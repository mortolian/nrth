<?php

namespace Database\Factories;

use App\Domain\Takeout\Enums\TakeoutRunStatus;
use App\Domain\Takeout\Models\TakeoutRun;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TakeoutRun>
 */
class TakeoutRunFactory extends Factory
{
    protected $model = TakeoutRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $from = now()->subYear()->startOfMonth();
        $to = now()->endOfMonth();

        return [
            'team_id' => Team::factory(),
            'requested_by' => User::factory(),
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'status' => TakeoutRunStatus::Queued,
            'download_token' => TakeoutRun::generateDownloadToken(),
            'storage_path' => null,
            'file_size_bytes' => null,
            'manifest' => null,
            'error_message' => null,
            'expires_at' => null,
            'completed_at' => null,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (): array => [
            'status' => TakeoutRunStatus::Ready,
            'storage_path' => 'takeouts/test.zip',
            'file_size_bytes' => 1024,
            'completed_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
    }
}
