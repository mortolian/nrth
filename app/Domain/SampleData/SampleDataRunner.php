<?php

namespace App\Domain\SampleData;

use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Models\BankingTransaction;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Reports\Models\StatementFilterReport;
use App\Domain\SampleData\Chapters\BankingChapter;
use App\Domain\SampleData\Chapters\ExpensesChapter;
use App\Domain\SampleData\Chapters\InvoicingChapter;
use App\Domain\SampleData\Chapters\PlanningChapter;
use App\Domain\SampleData\Chapters\TravelChapter;
use App\Domain\SampleData\Chapters\VatChapter;
use App\Domain\SampleData\Chapters\WealthChapter;

final class SampleDataRunner
{
    public function __construct(
        private readonly SampleBusiness $business,
    ) {}

    public function run(bool $previousYear = false): SampleRun
    {
        $started = hrtime(true);
        $calendar = SampleCalendar::make($previousYear);
        $context = $this->business->install($calendar);
        $chapterMilliseconds = [];

        foreach ($this->chapters() as $chapter) {
            $chapterStarted = hrtime(true);
            $chapter->seed($context);
            $chapterMilliseconds[$chapter->key()] = (int) round((hrtime(true) - $chapterStarted) / 1_000_000);
        }

        return new SampleRun(
            $context,
            (int) round((hrtime(true) - $started) / 1_000_000),
            $chapterMilliseconds,
            [
                'invoices' => Invoice::queryWithoutTeamScope()->where('team_id', $context->team->id)->count(),
                'expenses' => Transaction::queryWithoutTeamScope()->where('team_id', $context->team->id)->where('type', TransactionType::Expense)->count(),
                'statement_lines' => BankingTransaction::queryWithoutTeamScope()->where('team_id', $context->team->id)->count(),
                'statement_filters' => StatementFilterReport::queryWithoutTeamScope()->where('team_id', $context->team->id)->count(),
            ],
        );
    }

    /**
     * Add a chapter here when a new area should appear in the demo business.
     *
     * @return list<SampleChapter>
     */
    public function chapters(): array
    {
        return [
            new InvoicingChapter,
            new ExpensesChapter,
            new BankingChapter,
            new VatChapter,
            new TravelChapter,
            new PlanningChapter,
            new WealthChapter,
        ];
    }
}
