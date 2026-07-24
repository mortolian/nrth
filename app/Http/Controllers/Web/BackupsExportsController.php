<?php

namespace App\Http\Controllers\Web;

use App\Domain\Backup\Jobs\RunInstanceBackupJob;
use App\Domain\Backup\Services\InstanceBackupService;
use App\Domain\Takeout\Enums\TakeoutRunStatus;
use App\Domain\Takeout\Models\TakeoutRun;
use App\Domain\Takeout\Services\TakeoutPreviewService;
use App\Domain\Takeout\Support\TakeoutPeriodResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupsExportsController extends Controller
{
    public function __construct(
        private readonly TakeoutPeriodResolver $periodResolver,
        private readonly TakeoutPreviewService $previewService,
        private readonly InstanceBackupService $backups,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $team = $user->currentTeam;
        $isOwner = $team !== null && $user->ownsTeam($team);
        $canManageBackups = Gate::allows('manageInstanceBackups');

        abort_unless($isOwner || $canManageBackups, 403);

        $section = $request->string('section')->toString();
        if (! in_array($section, ['takeout', 'backup'], true)) {
            $section = $isOwner ? 'takeout' : 'backup';
        }

        if ($section === 'backup' && ! $canManageBackups) {
            $section = 'takeout';
        }

        if ($section === 'takeout' && ! $isOwner) {
            $section = 'backup';
        }

        $props = [
            'section' => $section,
            'can_generate_takeout' => $isOwner,
            'can_manage_backups' => $canManageBackups,
            'period' => null,
            'preview' => null,
            'document_categories' => [],
            'recent_takeouts' => [],
            'backups' => [],
            'backup_running' => false,
            'backup_last_error' => null,
            'backup_schedule_hint' => 'Scheduled daily at 03:00 (cleanup at 03:30). Restore is CLI/docs only — not available in the app.',
            'latest_backup_at' => null,
        ];

        if ($isOwner) {
            $props = array_merge($props, $this->takeoutProps($request, (int) $team->id));
        }

        if ($canManageBackups) {
            $listed = $this->backups->listBackups();
            $props['backups'] = $listed;
            $props['backup_running'] = $this->backups->isRunning();
            $props['backup_last_error'] = $this->backups->lastError();
            $props['latest_backup_at'] = $this->backups->latestBackupAt()?->toIso8601String();
        }

        return Inertia::render('BackupsExports/Index', $props);
    }

    public function storeBackup(Request $request): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        $key = 'instance-backup:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return redirect()
                ->route('backups-exports.index', ['section' => 'backup'])
                ->with('error', 'Please wait before starting another backup.');
        }

        if ($this->backups->isRunning()) {
            return redirect()
                ->route('backups-exports.index', ['section' => 'backup'])
                ->with('error', 'A backup is already running.');
        }

        RateLimiter::hit($key, 300);

        // Only the job sets the running flag. Marking here left a stuck "already
        // running" state when the worker failed before handle() cleared it.
        $this->backups->clearLastError();
        $this->backups->markFinished();
        RunInstanceBackupJob::dispatch($request->user()->id);

        activity()
            ->causedBy($request->user())
            ->withProperties(['action' => 'instance_backup_queued'])
            ->log('Queued instance backup');

        return redirect()
            ->route('backups-exports.index', ['section' => 'backup'])
            ->with('success', 'Instance backup has been queued. This page will refresh while it runs.');
    }

    public function downloadBackup(Request $request, string $filename): StreamedResponse
    {
        Gate::authorize('manageInstanceBackups');

        activity()
            ->causedBy($request->user())
            ->withProperties(['action' => 'instance_backup_download', 'filename' => basename($filename)])
            ->log('Downloaded instance backup');

        return $this->backups->download($filename);
    }

    public function destroyBackup(Request $request, string $filename): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        $deleted = $this->backups->delete($filename);
        abort_unless($deleted, 404);

        activity()
            ->causedBy($request->user())
            ->withProperties(['action' => 'instance_backup_delete', 'filename' => basename($filename)])
            ->log('Deleted instance backup');

        return redirect()
            ->route('backups-exports.index', ['section' => 'backup'])
            ->with('success', 'Backup deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function takeoutProps(Request $request, int $teamId): array
    {
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
            ->limit(25)
            ->get()
            ->map(fn (TakeoutRun $run): array => [
                'id' => $run->id,
                'download_token' => $run->download_token,
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
                'can_retry' => $run->status === TakeoutRunStatus::Failed,
            ])
            ->values()
            ->all();

        return [
            'period' => $period,
            'preview' => $preview,
            'document_categories' => $documentCategories,
            'recent_takeouts' => $recentTakeouts,
        ];
    }
}
