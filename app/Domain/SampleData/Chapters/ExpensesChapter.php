<?php

namespace App\Domain\SampleData\Chapters;

use App\Domain\Accounting\Models\Supplier;
use App\Domain\Expenses\Actions\CreateExpenseAction;
use App\Domain\Expenses\DTOs\CreateExpenseDTO;
use App\Domain\Expenses\Enums\RecurringExpenseStatus;
use App\Domain\Expenses\Models\RecurringExpense;
use App\Domain\Invoicing\Enums\RecurringFrequency;
use App\Domain\Invoicing\Enums\RecurringLimitType;
use App\Domain\SampleData\SampleChapter;
use App\Domain\SampleData\SampleContext;
use Carbon\Carbon;

final class ExpensesChapter implements SampleChapter
{
    public function key(): string
    {
        return 'expenses';
    }

    public function seed(SampleContext $context): void
    {
        $landlord = $this->supplier($context, 'Bree Street Properties', 'rent@breestreet.example');
        $city = $this->supplier($context, 'City of Cape Town', 'utilities@capetown.example');
        $payroll = $this->supplier($context, 'Northwind Payroll', 'pay@northwind.example');
        $auditor = $this->supplier($context, 'Kloof Accounting', 'hello@kloof.example');

        $create = app(CreateExpenseAction::class);

        foreach ($context->calendar->postedMonths() as $month) {
            $date = $month->copy()->day(min(3, $month->daysInMonth));
            if ($date->greaterThan($context->calendar->asOf)) {
                $date = $context->calendar->asOf->copy();
            }
            $label = $date->format('F Y');

            $this->expense($create, $context, $date, $landlord, '5200', "Studio rent for {$label}", 1_200_000, 'vat15');
            $this->expense($create, $context, $date, $payroll, '5100', "Salaries for {$label}", 4_500_000, 'no_vat');
            $this->expense($create, $context, $date->copy()->addDays(2)->min($context->calendar->asOf), $city, '5300', "Utilities for {$label}", 280_000, 'vat15');

            if (in_array((int) $month->month, [3, 6, 9, 12], true)) {
                $this->expense($create, $context, $date, $auditor, '5600', "Bookkeeping for {$label}", 450_000, 'vat15');
            }
        }

        $next = $context->calendar->futureMonths()[0] ?? $context->calendar->end->copy()->addDay();
        RecurringExpense::query()->create([
            'team_id' => $context->team->id,
            'supplier_id' => $landlord->id,
            'supplier_name' => $landlord->name,
            'category_account_id' => $context->account('5200')->id,
            'paid_from_banking_account_id' => $context->bank->id,
            'status' => RecurringExpenseStatus::Active,
            'frequency' => RecurringFrequency::Monthly,
            'generate_on_day' => 3,
            'limit_type' => RecurringLimitType::None,
            'generated_count' => 0,
            'next_run_date' => $next->toDateString(),
            'description' => 'Studio rent for {{month_year}}',
            'receipt_not_required' => false,
            'amount_excl_vat_cents' => 1_200_000,
            'vat_rate' => 'vat15',
            'vat_amount_cents' => 180_000,
        ]);
    }

    private function supplier(SampleContext $context, string $name, string $email): Supplier
    {
        return Supplier::query()->create([
            'team_id' => $context->team->id,
            'name' => $name,
            'email' => $email,
            'is_active' => true,
        ]);
    }

    private function expense(
        CreateExpenseAction $create,
        SampleContext $context,
        Carbon $date,
        Supplier $supplier,
        string $accountCode,
        string $description,
        int $exclusiveCents,
        string $vatRate,
    ): void {
        $vatCents = $vatRate === 'vat15' ? (int) round($exclusiveCents * 0.15) : 0;

        $create->execute(new CreateExpenseDTO(
            teamId: $context->team->id,
            createdBy: $context->user->id,
            date: $date->toDateString(),
            supplierId: $supplier->id,
            supplierName: $supplier->name,
            categoryAccountId: $context->account($accountCode)->id,
            description: $description,
            amountExclVatCents: $exclusiveCents,
            vatRate: $vatRate,
            vatAmountCents: $vatCents,
            paidFromBankingAccountId: $context->bank->id,
            receiptNotRequired: true,
        ));
    }
}
