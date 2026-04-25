<?php

namespace App\Domain\Accounting\Enums;

enum TransactionStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Void = 'void';
}
