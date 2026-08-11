<?php

namespace App\Modules\Wealth\Enums;

enum WealthAssetType: string
{
    case InvestmentAccount = 'investment_account';
    case SavingsAccount = 'savings_account';
    case TaxFreeSavings = 'tax_free_savings';
    case RetirementFund = 'retirement_fund';
    case Cash = 'cash';
    case Gold = 'gold';
    case Silver = 'silver';
    case Cryptocurrency = 'cryptocurrency';
    case Property = 'property';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::InvestmentAccount => 'Investment account',
            self::SavingsAccount => 'Savings account',
            self::TaxFreeSavings => 'Tax-free savings',
            self::RetirementFund => 'Retirement fund',
            self::Cash => 'Cash',
            self::Gold => 'Gold',
            self::Silver => 'Silver',
            self::Cryptocurrency => 'Cryptocurrency',
            self::Property => 'Property',
            self::Other => 'Other',
        };
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
