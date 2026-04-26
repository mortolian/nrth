<?php

namespace App\Http\Controllers\Web\Tax;

use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Tax\Actions\CreateTaxPeriodAction;
use App\Domain\Tax\Enums\TaxPeriodType;
use App\Domain\Tax\Models\TaxPeriod;
use App\Domain\Tax\Services\ProvisionalTaxService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProvisionalTaxController extends Controller
{
    public function __construct(
        private readonly ProvisionalTaxService $provisionalTaxService,
        private readonly CreateTaxPeriodAction $createTaxPeriodAction,
    ) {}

    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        $teamId = (int) $team->id;

        $taxYearWindow = $this->provisionalTaxService->getCurrentTaxYear($team);
        $taxYearStart = $taxYearWindow['start'];
        $taxYearEnd = $taxYearWindow['end'];
        $taxYear = (int) $taxYearStart->format('Y');

        $this->createTaxPeriodAction->execute($teamId, $taxYear);

        $periods = TaxPeriod::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('type', TaxPeriodType::Provisional->value)
            ->whereDate('period_start', '>=', $taxYearStart->toDateString())
            ->whereDate('period_end', '<=', $taxYearEnd->toDateString())
            ->orderBy('period_start')
            ->get();

        $annualEstimateCents = $this->provisionalTaxService
            ->estimateAnnualIncome($team, $taxYear)
            ->getMinorAmount()
            ->toInt();
        $manualEstimateCents = (int) $request->integer('manual_estimate_cents', 0);
        $incomeEstimateCents = $manualEstimateCents > 0 ? $manualEstimateCents : $annualEstimateCents;

        $previousYearTax = (int) round($this->roughTax((int) round($annualEstimateCents * 0.9)));
        $currentTaxEstimate = (int) round($this->roughTax($incomeEstimateCents));

        $provisionalPayments = Transaction::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('type', TransactionType::Payment->value)
            ->where('status', TransactionStatus::Posted->value)
            ->where(function ($q): void {
                $q->where('description', 'like', '%provisional%tax%')
                    ->orWhere('reference', 'like', '%provisional%tax%');
            })
            ->orderByDesc('transaction_date')
            ->limit(20)
            ->get()
            ->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'date' => optional($transaction->transaction_date)->toDateString(),
                'reference' => $transaction->reference,
                'description' => $transaction->description,
            ])
            ->values()
            ->all();

        $periodCards = $periods->map(function (TaxPeriod $period, int $index) use ($incomeEstimateCents, $previousYearTax): array {
            $suggested = $index === 0
                ? (int) round($this->roughTax($incomeEstimateCents) * 0.5)
                : (int) round($this->roughTax($incomeEstimateCents));
            $safeHarbour = (int) round(($previousYearTax * 0.9) / ($index === 0 ? 2 : 1));
            $dueDate = $period->due_date?->copy();
            $isOverdue = $dueDate !== null && $dueDate->isPast() && $period->status->value !== 'submitted';

            return [
                'id' => $period->id,
                'label' => $index === 0 ? 'First Period' : 'Second Period',
                'period_start' => optional($period->period_start)->toDateString(),
                'period_end' => optional($period->period_end)->toDateString(),
                'due_date' => optional($period->due_date)->toDateString(),
                'status' => $period->status->value === 'submitted' ? 'paid' : ($isOverdue ? 'overdue' : 'upcoming'),
                'estimated_income' => $incomeEstimateCents,
                'suggested_payment' => $suggested,
                'safe_harbour' => $safeHarbour,
            ];
        })->values()->all();

        $monthsElapsed = max(1, now()->month >= 3 ? now()->month - 2 : now()->month + 10);
        $ytdIncome = (int) round(($annualEstimateCents / 12) * $monthsElapsed);
        $projectedAnnual = (int) round(($ytdIncome / $monthsElapsed) * 12);

        return Inertia::render('Tax/Provisional/Index', [
            'tax_year' => [
                'label' => $taxYearStart->format('Y').'/'.$taxYearEnd->format('Y'),
                'start' => $taxYearStart->toDateString(),
                'end' => $taxYearEnd->toDateString(),
            ],
            'periods' => $periodCards,
            'income_estimate' => [
                'ytd_actual' => $ytdIncome,
                'projected_annual' => $projectedAnnual,
                'manual_estimate' => $manualEstimateCents > 0 ? $manualEstimateCents : null,
                'used_estimate' => $incomeEstimateCents,
                'tax_estimate' => $currentTaxEstimate,
            ],
            'previous_year_tax' => $previousYearTax,
            'payments' => $provisionalPayments,
        ]);
    }

    private function roughTax(int $annualIncomeCents): int
    {
        $income = $annualIncomeCents / 100;

        // Rough SA individual tables (estimate only).
        $tax = match (true) {
            $income <= 237_100 => $income * 0.18,
            $income <= 370_500 => 42_678 + (($income - 237_100) * 0.26),
            $income <= 512_800 => 77_362 + (($income - 370_500) * 0.31),
            $income <= 673_000 => 121_475 + (($income - 512_800) * 0.36),
            $income <= 857_900 => 179_147 + (($income - 673_000) * 0.39),
            $income <= 1_817_000 => 251_258 + (($income - 857_900) * 0.41),
            default => 644_489 + (($income - 1_817_000) * 0.45),
        };

        return (int) round(max(0, $tax) * 100);
    }
}
