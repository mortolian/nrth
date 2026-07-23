<?php

namespace App\Domain\Banking\Enums;

enum ImportStatus: string
{
    case Pending = 'pending';
    case Parsed = 'parsed';
    case Imported = 'imported';
    case Undone = 'undone';
    case Failed = 'failed';
}
