<?php

namespace Tests\Feature\Tax;

use App\Domain\Takeout\Enums\TakeoutRunStatus;
use App\Domain\Takeout\Models\TakeoutRun;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneTakeoutsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_deletes_expired_files_and_marks_runs_expired(): void
    {
        Storage::fake('local');

        $team = Team::factory()->create();
        $path = 'takeouts/to-prune.zip';
        Storage::disk('local')->put($path, 'zip-contents');

        $run = TakeoutRun::factory()->for($team)->ready()->create([
            'storage_path' => $path,
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('takeouts:prune')->assertSuccessful();

        $run->refresh();
        $this->assertSame(TakeoutRunStatus::Expired, $run->status);
        $this->assertNull($run->storage_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_prune_leaves_active_takeouts_unchanged(): void
    {
        Storage::fake('local');

        $team = Team::factory()->create();
        $path = 'takeouts/active.zip';
        Storage::disk('local')->put($path, 'zip-contents');

        $run = TakeoutRun::factory()->for($team)->ready()->create([
            'storage_path' => $path,
            'expires_at' => now()->addDays(3),
        ]);

        $this->artisan('takeouts:prune')->assertSuccessful();

        $run->refresh();
        $this->assertSame(TakeoutRunStatus::Ready, $run->status);
        $this->assertSame($path, $run->storage_path);
        Storage::disk('local')->assertExists($path);
    }
}
