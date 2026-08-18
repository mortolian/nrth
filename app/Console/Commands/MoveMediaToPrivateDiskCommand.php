<?php

namespace App\Console\Commands;

use App\Support\MediaDisks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class MoveMediaToPrivateDiskCommand extends Command
{
    protected $signature = 'nrth:move-media-to-private-disk';

    protected $description = 'Move invoice PDFs, receipts, and contracts off the public disk onto the private media disk.';

    public function handle(): int
    {
        $private = MediaDisks::private();
        $moved = 0;
        $failed = 0;

        Media::query()
            ->where('disk', 'public')
            ->where('collection_name', '!=', 'logo')
            ->orderBy('id')
            ->each(function (Media $media) use ($private, &$moved, &$failed): void {
                try {
                    $this->moveOne($media, $private);
                    $moved++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->components->error("Media {$media->id}: {$e->getMessage()}");
                }
            });

        $this->components->info("Moved {$moved} media file(s) to disk [{$private}].");
        if ($failed > 0) {
            $this->components->warn("{$failed} file(s) could not be moved.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function moveOne(Media $media, string $newDisk): void
    {
        $oldDisk = (string) $media->disk;
        if ($oldDisk === $newDisk) {
            return;
        }

        $from = Storage::disk($oldDisk);
        $to = Storage::disk($newDisk);
        $prefix = (string) $media->getKey();
        $files = $from->allFiles($prefix);

        foreach ($files as $file) {
            $stream = $from->readStream($file);
            if ($stream === null) {
                continue;
            }
            $to->writeStream($file, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $media->disk = $newDisk;
        $conversionsDisk = (string) $media->conversions_disk;
        if ($conversionsDisk === '' || $conversionsDisk === $oldDisk) {
            $media->conversions_disk = $newDisk;
        }
        $media->save();

        foreach ($files as $file) {
            $from->delete($file);
        }
    }
}
