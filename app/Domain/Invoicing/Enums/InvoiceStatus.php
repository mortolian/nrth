<?php

namespace App\Domain\Invoicing\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Viewed = 'viewed';
    case Partial = 'partial';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Void = 'void';

    /**
     * Issued (non-draft, non-void) invoices — financial totals, statements, VAT output.
     *
     * @return list<self>
     */
    public static function issuedStatuses(): array
    {
        return [
            self::Sent,
            self::Viewed,
            self::Partial,
            self::Paid,
            self::Overdue,
        ];
    }

    /**
     * Open / outstanding — issued invoices that are not fully paid.
     *
     * @return list<self>
     */
    public static function openStatuses(): array
    {
        return [
            self::Sent,
            self::Viewed,
            self::Partial,
            self::Overdue,
        ];
    }

    /**
     * @return list<string>
     */
    public static function issuedValues(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::issuedStatuses());
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::openStatuses());
    }

    public function isIssued(): bool
    {
        return in_array($this, self::issuedStatuses(), true);
    }

    public function isOpen(): bool
    {
        return in_array($this, self::openStatuses(), true);
    }
}
