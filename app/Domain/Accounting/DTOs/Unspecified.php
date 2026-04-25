<?php

namespace App\Domain\Accounting\DTOs;

/**
 * Marks an optional field on {@see UpdateAccountDTO} as “leave unchanged”.
 */
enum Unspecified
{
    case Value;
}
