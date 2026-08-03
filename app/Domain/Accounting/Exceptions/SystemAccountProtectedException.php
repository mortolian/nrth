<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class SystemAccountProtectedException extends RuntimeException
{
    public static function cannotDelete(): self
    {
        return new self('System accounts cannot be deleted.');
    }

    public static function cannotRename(): self
    {
        return new self('System account code, type, and hierarchy cannot be changed.');
    }

    public static function cannotDeactivate(): self
    {
        return new self('System accounts cannot be deactivated.');
    }
}
