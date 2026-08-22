<?php

namespace App\Domain\Budgeting\Models;

use App\Domain\Budgeting\Enums\BudgetItemCadence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetItem extends Model
{
    protected $fillable = [
        'budget_category_id',
        'label',
        'cadence',
        'monthly_amount_cents',
        'currency',
        'monthly_budget_currency_cents',
        'fx_budget_per_line_major',
        'notes',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cadence' => BudgetItemCadence::class,
        ];
    }

    /**
     * @return BelongsTo<BudgetCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class, 'budget_category_id');
    }

    public function cadenceEnum(): BudgetItemCadence
    {
        return $this->cadence instanceof BudgetItemCadence
            ? $this->cadence
            : BudgetItemCadence::tryFrom((string) $this->cadence) ?? BudgetItemCadence::Monthly;
    }

    /**
     * Amount in budget currency attributed to one calendar month.
     * Once-offs and annual amounts are spread over the relevant span.
     */
    public function monthlyEquivalentBudgetCents(int $monthsInPeriod): int
    {
        $amount = (int) $this->monthly_budget_currency_cents;
        $months = max(1, $monthsInPeriod);

        return match ($this->cadenceEnum()) {
            BudgetItemCadence::OncePerPeriod => (int) round($amount / $months),
            BudgetItemCadence::Annually => (int) round($amount / 12),
            BudgetItemCadence::Monthly => $amount,
        };
    }

    /**
     * Full amount in budget currency for the budget period.
     */
    public function periodTotalBudgetCents(int $monthsInPeriod): int
    {
        $amount = (int) $this->monthly_budget_currency_cents;
        $months = max(1, $monthsInPeriod);

        return match ($this->cadenceEnum()) {
            BudgetItemCadence::OncePerPeriod => $amount,
            BudgetItemCadence::Annually => (int) round($amount * ($months / 12)),
            BudgetItemCadence::Monthly => $amount * $months,
        };
    }

    /**
     * Run-rate annualised to 12 months for comparison.
     */
    public function annualizedBudgetCents(int $monthsInPeriod): int
    {
        $months = max(1, $monthsInPeriod);

        return match ($this->cadenceEnum()) {
            BudgetItemCadence::OncePerPeriod => (int) round(((int) $this->monthly_budget_currency_cents) * (12 / $months)),
            BudgetItemCadence::Annually => (int) $this->monthly_budget_currency_cents,
            BudgetItemCadence::Monthly => (int) $this->monthly_budget_currency_cents * 12,
        };
    }
}
