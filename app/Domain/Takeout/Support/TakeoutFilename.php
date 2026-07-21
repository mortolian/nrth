<?php

namespace App\Domain\Takeout\Support;

final class TakeoutFilename
{
    public static function sanitize(string $value, int $maxLength = 80): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $value) ?? '';
        $sanitized = preg_replace('/-+/', '-', $sanitized) ?? '';
        $sanitized = trim($sanitized, '-');

        if ($sanitized === '') {
            return 'file';
        }

        if (strlen($sanitized) > $maxLength) {
            return substr($sanitized, 0, $maxLength);
        }

        return $sanitized;
    }
}
