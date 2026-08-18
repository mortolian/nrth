<?php

namespace App\Support;

final class MediaDisks
{
    public static function private(): string
    {
        $disk = (string) config('media-library.disk_name', 'local');

        return $disk !== '' ? $disk : 'local';
    }
}
