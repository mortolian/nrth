<?php

namespace App\Support;

final class DownloadFilename
{
    public static function sanitize(string $name, string $fallback = 'download'): string
    {
        $name = str_replace(["\r", "\n", '"', '/', '\\'], '', $name);
        $name = trim($name);

        return $name !== '' ? $name : $fallback;
    }
}
