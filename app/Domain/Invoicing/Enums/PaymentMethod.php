<?php

namespace App\Domain\Invoicing\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Eft = 'eft';
    case Card = 'card';
    case Other = 'other';
}
