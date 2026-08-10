<?php

namespace App\Domain\Vehicles\Enums;

enum TripImportStatus: string
{
    case Imported = 'imported';
    case Undone = 'undone';
}
