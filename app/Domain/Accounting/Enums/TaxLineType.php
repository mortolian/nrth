<?php

namespace App\Domain\Accounting\Enums;

enum TaxLineType: string
{
    case Input = 'input';
    case Output = 'output';
}
