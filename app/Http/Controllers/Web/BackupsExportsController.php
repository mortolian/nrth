<?php

namespace App\Http\Controllers\Web;

use App\Domain\Backup\Enums\InstanceBackupRunStatus;
use App\Domain\Backup\Jobs\RunInstanceBackupJob;
use App\Domain\Backup\Models\InstanceBackupRun;
use App\Domain\Backup\Services\InstanceBackupService;
use App\Domain\Instance\Services\InstanceBackupRetentionSettings;
use App\Domain\Instance\Services\InstanceOperatorService;
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
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupsExportsController extends Controller
{
    public function __construct(
        private readonly TakeoutPeriodResolver $periodResolver,
        private readonly TakeoutPreviewService $previewService,
        private readonly InstanceBackupService $backups,
        private readonly InstanceOperatorService $operators,
        private readonly InstanceBackupRetentionSettings $backupRetention,
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
            'recent_backups' => [],
            'operators' => [],
            'env_break_glass_configured' => false,
            'backup_schedule_hint' => 'Scheduled daily at 03:00 (cleanup at 03:30). Retention below decides how long daily/weekly/monthly copies are kept. Use the restore guide for CLI recovery — there is no one-click restore in the app.',
            'restore_guide' => null,
            'backup_retention' => null,
        ];

        if ($isOwner) {
            $props = array_merge($props, $this->takeoutProps($request, (int) $team->id));
        }

        if ($canManageBackups) {
            $this->backups->syncDiskBackupsIntoRuns();
            $props['recent_backups'] = $this->backupRunProps();
            $props['restore_guide'] = $this->backups->restoreGuideProps();
            $props['operators'] = $this->operators->listEffectiveOperators();
            $props['env_break_glass_configured'] = $this->operators->envOperatorEmails() !== [];
            $props['backup_retention'] = $this->backupRetention->current();
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

        if ($this->backups->hasActiveRun()) {
            return redirect()
                ->route('backups-exports.index', ['section' => 'backup'])
                ->with('error', 'A backup is already queued or running.');
        }

        RateLimiter::hit($key, 300);

        $this->backups->clearLastError();

        $run = InstanceBackupRun::query()->create([
            'requested_by' => $request->user()->id,
            'status' => InstanceBackupRunStatus::Queued,
        ]);

        RunInstanceBackupJob::dispatch($run->id);

        activity()
            ->causedBy($request->user())
            ->withProperties(['action' => 'instance_backup_queued', 'backup_run_id' => $run->id])
            ->log('Queued instance backup');

        return redirect()
            ->route('backups-exports.index', ['section' => 'backup'])
            ->with('success', 'Instance backup is being prepared.');
    }

    public function downloadBackup(Request $request, InstanceBackupRun $instanceBackupRun): BinaryFileResponse|StreamedResponse
    {
        Gate::authorize('manageInstanceBackups');
        abort_unless($instanceBackupRun->isDownloadable(), 404);
        abort_unless(filled($instanceBackupRun->filename), 404);

        activity()
            ->causedBy($request->user())
            ->withProperties([
                'action' => 'instance_backup_download',
                'backup_run_id' => $instanceBackupRun->id,
                'filename' => $instanceBackupRun->filename,
            ])
            ->log('Downloaded instance backup');

        return $this->backups->download((string) $instanceBackupRun->filename);
    }

    public function destroyBackup(Request $request, InstanceBackupRun $instanceBackupRun): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        if (filled($instanceBackupRun->filename)) {
            $this->backups->delete((string) $instanceBackupRun->filename);
        }

        $instanceBackupRun->delete();

        activity()
            ->causedBy($request->user())
            ->withProperties(['action' => 'instance_backup_delete', 'backup_run_id' => $instanceBackupRun->id])
            ->log('Deleted instance backup');

        return redirect()
            ->route('backups-exports.index', ['section' => 'backup'])
            ->with('success', 'Backup deleted.');
    }

    public function retryBackup(Request $request, InstanceBackupRun $instanceBackupRun): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');
        abort_unless($instanceBackupRun->status === InstanceBackupRunStatus::Failed, 422);

        if ($this->backups->hasActiveRun()) {
            return redirect()
                ->route('backups-exports.index', ['section' => 'backup'])
                ->with('error', 'A backup is already queued or running.');
        }

        $instanceBackupRun->forceFill([
            'status' => InstanceBackupRunStatus::Queued,
            'filename' => null,
            'disk' => null,
            'storage_path' => null,
            'file_size_bytes' => null,
            'error_message' => null,
            'completed_at' => null,
        ])->save();

        RunInstanceBackupJob::dispatch($instanceBackupRun->id);

        return redirect()
            ->route('backups-exports.index', ['section' => 'backup'])
            ->with('success', 'Backup has been re-queued.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function backupRunProps(): array
    {
        return InstanceBackupRun::query()
            ->orderByDesc('created_at')
            ->limit(25)
            ->get()
            ->map(fn (InstanceBackupRun $run): array => [
                'id' => $run->id,
                'status' => $run->status->value,
                'filename' => $run->filename,
                'created_at' => $run->created_at?->toIso8601String(),
                'completed_at' => $run->completed_at?->toIso8601String(),
                'file_size_bytes' => $run->file_size_bytes,
                'download_url' => $run->isDownloadable()
                    ? route('backups-exports.backups.download', $run)
                    : null,
                'error_message' => $run->error_message,
                'can_retry' => $run->status === InstanceBackupRunStatus::Failed,
            ])
            ->values()
            ->all();
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
