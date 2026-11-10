<?php

namespace App\Domain\SampleData\Chapters;

use App\Domain\Budgeting\Enums\BudgetItemCadence;
use App\Domain\Budgeting\Models\Budget;
use App\Domain\Budgeting\Models\BudgetCategory;
use App\Domain\Budgeting\Models\BudgetItem;
use App\Domain\SampleData\SampleChapter;
use App\Domain\SampleData\SampleContext;

final class PlanningChapter implements SampleChapter
{
    public function key(): string
    {
        return 'planning';
    }

    public function seed(SampleContext $context): void
    {
        $budget = Budget::query()->create([
            'team_id' => $context->team->id,
            'name' => 'Studio '.$context->calendar->label(),
            'period_type' => 'financial_year',
            'start_date' => $context->calendar->start->toDateString(),
            'end_date' => $context->calendar->end->toDateString(),
            'currency' => 'ZAR',
            'is_active' => true,
        ]);

        $lines = [
            ['People', '5100', 'Salaries', 4_500_000],
            ['Premises', '5200', 'Rent', 1_200_000],
            ['Premises', '5300', 'Utilities', 280_000],
            ['Practice', '5600', 'Bookkeeping', 150_000],
        ];

        $categories = [];
        $order = 0;
        foreach ($lines as [$categoryName, $code, $label, $cents]) {
            if (! isset($categories[$categoryName])) {
                $categories[$categoryName] = BudgetCategory::query()->create([
                    'budget_id' => $budget->id,
                    'name' => $categoryName,
                    'account_id' => null,
                    'sort_order' => count($categories),
                ]);
            }

            BudgetItem::query()->create([
                'budget_category_id' => $categories[$categoryName]->id,
                'label' => $label,
                'cadence' => BudgetItemCadence::Monthly,
                'monthly_amount_cents' => $cents,
                'currency' => 'ZAR',
                'monthly_budget_currency_cents' => $cents,
                'notes' => 'Linked to chart '.$code,
                'sort_order' => $order,
            ]);
            $order++;
        }
    }
}
