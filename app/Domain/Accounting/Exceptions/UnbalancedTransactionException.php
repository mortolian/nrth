<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class UnbalancedTransactionException extends RuntimeException
{
    public static function make(int $debitCents, int $creditCents): self
    {
        return new self(sprintf(
            'Transaction is not balanced: debit total %d cents does not equal credit total %d cents.',
            $debitCents,
            $creditCents
        ));
    }
}
