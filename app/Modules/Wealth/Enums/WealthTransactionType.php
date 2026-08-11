<?php

namespace App\Modules\Wealth\Enums;

enum WealthTransactionType: string
{
    case Contribution = 'contribution';
    case Withdrawal = 'withdrawal';
    case Interest = 'interest';
    case Dividend = 'dividend';
    case Fee = 'fee';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Contribution => 'Contribution',
            self::Withdrawal => 'Withdrawal',
            self::Interest => 'Interest',
            self::Dividend => 'Dividend',
            self::Fee => 'Fee',
            self::Adjustment => 'Adjustment',
        };
    }

    /**
     * Signed minor units for cash-flow netting in investment movement.
     * Positive = money in; negative = money out.
     */
    public function signedFlowCents(int $amountCents): int
    {
        return match ($this) {
            self::Contribution, self::Interest, self::Dividend => abs($amountCents),
            self::Withdrawal, self::Fee => -abs($amountCents),
            self::Adjustment => $amountCents,
        };
    }

    public function countsAsContribution(): bool
    {
        return $this === self::Contribution;
    }

    public function countsAsWithdrawal(): bool
    {
        return $this === self::Withdrawal;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
