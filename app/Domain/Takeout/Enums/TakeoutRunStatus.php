<?php

namespace App\Domain\Takeout\Enums;

enum TakeoutRunStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';
}
