<?php

namespace App\Domain\SampleData\Chapters;

use App\Domain\Banking\Actions\ExcludeBankingTransactionAction;
use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Banking\Models\BankingTransaction;
use App\Domain\Reports\Models\StatementFilterReport;
use App\Domain\SampleData\SampleChapter;
use App\Domain\SampleData\SampleContext;

final class BankingChapter implements SampleChapter
{
    public function key(): string
    {
        return 'banking';
    }

    public function seed(SampleContext $context): void
    {
        $personal = null;

        foreach ($context->calendar->postedMonths() as $index => $month) {
            $date = $month->copy()->day(min(5, $month->daysInMonth));
            if ($date->greaterThan($context->calendar->asOf)) {
                $date = $context->calendar->asOf->copy();
            }
            $day = $date->toDateString();

            $this->line($context, $day, 'EFT BREE STREET PROPERTIES RENT', 'RENT', 13_800.00, TransactionDirection::Debit, "rent-{$day}");
            $this->line($context, $day, 'SALARIES NORTHWIND PAYROLL', 'SALARY', 45_000.00, TransactionDirection::Debit, "salary-{$day}");
            $this->line($context, $day, 'POS PURCHASE WOOLWORTHS FOOD V&A', 'CARD', 800 + ($index * 17), TransactionDirection::Debit, "woolies-{$day}");
            $this->line($context, $day, 'POS PURCHASE CHECKERS HEIDERAND', 'CARD', 640 + ($index * 11), TransactionDirection::Debit, "checkers-{$day}");
            $this->line($context, $day, 'SHELL FUEL CENTURY CITY', 'CARD', 950 + ($index * 13), TransactionDirection::Debit, "shell-{$day}");
            $this->line($context, $day, 'EFT HARBOUR AND CO', $context->clients['harbour']->name ?? 'Harbour', 20_700.00, TransactionDirection::Credit, "harbour-{$day}");

            if ($index === 0) {
                $personal = $this->line($context, $day, 'TAKEALOT ONLINE ORDER', 'CARD', 429.00, TransactionDirection::Debit, "takealot-{$day}");
            }
        }

        if ($personal !== null) {
            app(ExcludeBankingTransactionAction::class)->execute(
                $personal,
                $context->user->id,
                'Personal shopping, not a studio expense.',
            );
        }

        $report = StatementFilterReport::query()->create([
            'team_id' => $context->team->id,
            'name' => 'Card spend',
        ]);
        $report->replaceRules([
            ['alias' => 'Woolies', 'match' => 'Woolworths'],
            ['alias' => 'Checkers', 'match' => 'Checkers'],
            ['alias' => 'Fuel', 'match' => 'Shell'],
        ]);
    }

    private function line(
        SampleContext $context,
        string $date,
        string $description,
        string $reference,
        float $amount,
        TransactionDirection $direction,
        string $key,
    ): BankingTransaction {
        $hash = hash('sha256', $context->team->id.'|'.$key);

        return BankingTransaction::queryWithoutTeamScope()->create([
            'team_id' => $context->team->id,
            'account_id' => $context->bank->id,
            'transaction_date' => $date,
            'description' => $description,
            'reference' => $reference,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'ZAR',
            'direction' => $direction,
            'source_hash' => $hash,
            'duplicate_key' => hash('sha256', $hash.'|dup'),
        ]);
    }
}
