<?php

namespace Tests\Feature\Media;

use App\Domain\Invoicing\Models\Invoice;
use App\Models\User;
use App\Support\MediaDisks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivateMediaDiskTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_pdfs_are_stored_on_the_private_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $invoice = Invoice::factory()->for($team)->create();
        $media = $invoice
            ->addMedia(UploadedFile::fake()->create('INV-2026-0001.pdf', 20, 'application/pdf'))
            ->toMediaCollection('invoice-pdfs');

        $this->assertSame(MediaDisks::private(), $media->disk);
        $this->assertTrue(Storage::disk('local')->exists($media->getPathRelativeToRoot()));
        $this->assertFalse(Storage::disk('public')->exists($media->getPathRelativeToRoot()));
    }

    public function test_move_command_relocates_public_financial_media(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $invoice = Invoice::factory()->for($team)->create();
        $media = $invoice
            ->addMedia(UploadedFile::fake()->create('INV-old.pdf', 20, 'application/pdf'))
            ->toMediaCollection('invoice-pdfs', 'public');

        $this->assertSame('public', $media->disk);
        $relative = $media->getPathRelativeToRoot();
        $this->assertTrue(Storage::disk('public')->exists($relative));

        $this->artisan('nrth:move-media-to-private-disk')->assertSuccessful();

        $media->refresh();
        $this->assertSame('local', $media->disk);
        $this->assertTrue(Storage::disk('local')->exists($relative));
        $this->assertFalse(Storage::disk('public')->exists($relative));
    }

    public function test_logos_remain_on_the_public_disk(): void
    {
        Storage::fake('public');

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $media = $team
            ->addMedia(UploadedFile::fake()->image('logo.png', 80, 80))
            ->toMediaCollection('logo');

        $this->assertSame('public', $media->disk);
    }
}
