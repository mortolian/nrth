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
        return new self('System accounts cannot be renamed (code or name).');
    }

    public static function cannotDeactivate(): self
    {
        return new self('System accounts cannot be deactivated.');
    }
}
