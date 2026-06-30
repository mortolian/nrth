<?php

namespace Tests\Feature\Tax;

use App\Domain\Takeout\Jobs\GenerateTakeoutJob;
use App\Domain\Takeout\Models\TakeoutRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class GenerateTakeoutJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_builds_zip_with_figure_registers(): void
    {
        Storage::fake('local');

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $run = TakeoutRun::factory()->for($team)->create([
            'requested_by' => $user->id,
            'from_date' => now()->startOfYear()->toDateString(),
            'to_date' => now()->endOfYear()->toDateString(),
        ]);

        (new GenerateTakeoutJob($run->id))->handle(app(\App\Domain\Takeout\Services\TakeoutBuilder::class));

        $run->refresh();

        $this->assertSame('ready', $run->status->value);
        $this->assertNotNull($run->storage_path);
        $this->assertNotNull($run->expires_at);
        $this->assertTrue($run->expires_at->isFuture());

        $zipPath = storage_path('app/private/'.$run->storage_path);
        $this->assertFileExists($zipPath);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);

        $root = sprintf(
            'nrth-takeout_%s_to_%s',
            $run->from_date->format('Y-m-d'),
            $run->to_date->format('Y-m-d'),
        );

        $this->assertNotFalse($zip->locateName($root.'/figures/income-statement.csv'));
        $this->assertNotFalse($zip->locateName($root.'/figures/invoices-register.xlsx'));
        $this->assertNotFalse($zip->locateName($root.'/README.txt'));
        $this->assertNotFalse($zip->locateName($root.'/manifest.json'));
        $zip->close();
    }
}
