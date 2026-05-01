<?php

declare(strict_types=1);

namespace App\Support;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

final class InvoicePayQrCode
{
    public static function pngBinary(string $data, int $size = 220, int $margin = 8): string
    {
        $builder = new Builder(
            writer: new PngWriter,
            writerOptions: [],
            validateResult: false,
            data: $data,
            size: $size,
            margin: $margin,
        );

        return $builder->build()->getString();
    }

    public static function pngDataUri(string $data, int $size = 220, int $margin = 8): string
    {
        return 'data:image/png;base64,'.base64_encode(self::pngBinary($data, $size, $margin));
    }
}
