<?php

namespace App\Http\Controllers\Web\Tax;

use App\Domain\Takeout\Models\TakeoutRun;
use App\Domain\Takeout\Services\TakeoutPreviewService;
use App\Domain\Takeout\Support\TakeoutPeriodResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaxDocumentsController extends Controller
{
    public function __construct(
        private readonly TakeoutPeriodResolver $periodResolver,
        private readonly TakeoutPreviewService $previewService,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $team = $user->currentTeam;
        abort_unless($team !== null && $user->ownsTeam($team), 403);

        $teamId = (int) $team->id;
        $period = $this->periodResolver->resolve(
            $request->string('preset')->toString() ?: null,
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null,
        );

        $previewRun = new TakeoutRun([
            'team_id' => $teamId,
            'from_date' => $period['from'],
            'to_date' => $period['to'],
        ]);

        $preview = $this->previewService->build($previewRun);

        $documentCategories = [
            [
                'key' => 'invoices',
                'label' => 'Invoices',
                'count' => $preview['invoices_count'],
                'total' => $preview['invoices_total_cents'],
                'warning' => null,
            ],
            [
                'key' => 'expense_receipts',
                'label' => 'Expense receipts',
                'count' => $preview['expense_receipts_count'],
                'total' => $preview['expenses_total_cents'],
                'warning' => $preview['expenses_missing_receipts'] > 0
                    ? "{$preview['expenses_missing_receipts']} missing receipts"
                    : null,
            ],
            [
                'key' => 'vat_returns',
                'label' => 'VAT periods',
                'count' => $preview['vat_periods_count'],
                'total' => 0,
                'warning' => null,
            ],
            [
                'key' => 'contracts',
                'label' => 'Contracts',
                'count' => $preview['contracts_count'],
                'total' => 0,
                'warning' => $preview['contracts_missing_signed_file'] > 0
                    ? "{$preview['contracts_missing_signed_file']} without signed file"
                    : null,
            ],
            [
                'key' => 'bank_statements',
                'label' => 'Bank statements',
                'count' => $preview['bank_statement_files'],
                'total' => 0,
                'warning' => $preview['bank_statement_files'] === 0 ? 'No statement files for this period' : null,
            ],
        ];

        $recentTakeouts = TakeoutRun::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (TakeoutRun $run): array => [
                'id' => $run->id,
                'from_date' => $run->from_date->toDateString(),
                'to_date' => $run->to_date->toDateString(),
                'status' => $run->status->value,
                'created_at' => $run->created_at?->toIso8601String(),
                'expires_at' => $run->expires_at?->toIso8601String(),
                'file_size_bytes' => $run->file_size_bytes,
                'download_url' => $run->isDownloadable()
                    ? route('tax.takeouts.download', $run)
                    : null,
                'error_message' => $run->error_message,
            ])
            ->values()
            ->all();

        return Inertia::render('Tax/Documents/Index', [
            'period' => $period,
            'preview' => $preview,
            'document_categories' => $documentCategories,
            'recent_takeouts' => $recentTakeouts,
            'can_generate_takeout' => true,
        ]);
    }
}
