<?php

namespace App\Domain\Backup\Enums;

enum InstanceBackupRunStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
