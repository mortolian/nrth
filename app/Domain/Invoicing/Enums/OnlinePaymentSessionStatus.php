<?php

namespace App\Domain\Invoicing\Enums;

enum OnlinePaymentSessionStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function canCompleteGatewayPayment(): bool
    {
        return $this === self::Pending || $this === self::Cancelled;
    }
}
