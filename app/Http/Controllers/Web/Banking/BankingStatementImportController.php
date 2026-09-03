<?php

namespace App\Http\Controllers\Web\Banking;

use App\Domain\Banking\Enums\ImportStatus;
use App\Domain\Banking\Importers\CsvBankStatementImporter;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Models\BankingStatementImport;
use App\Domain\Banking\Services\BankingStatementImportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BankingStatementImportController extends Controller
{
    private const QUEUE_SESSION_KEY = 'banking_import_queue';

    private const MAX_BATCH_FILES = 12;

    public function __construct(
        private readonly BankingStatementImportService $importService,
        private readonly CsvBankStatementImporter $csvImporter,
    ) {}

    public function create(Request $request): Response
    {
        $this->authorizeTeam('banking.manage', $request);

        return Inertia::render('Banking/Import/Upload', [
            'accounts' => $this->accountOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTeam('banking.manage', $request);

        $fileRules = $this->statementFileRules();
        $request->validate([
            'account_id' => [
                'required',
                Rule::exists('banking_accounts', 'id')->where(
                    'team_id',
                    $request->user()->current_team_id
                ),
            ],
            'file' => array_merge(['required_without:files'], $fileRules),
            'files' => ['required_without:file', 'array', 'min:1', 'max:'.self::MAX_BATCH_FILES],
            'files.*' => $fileRules,
        ]);

        $files = $this->uploadedStatementFiles($request);
        if ($files === []) {
            return back()->withErrors([
                'files' => __('Upload at least one CSV, TXT, or OFX file.'),
            ]);
        }

        $account = BankingAccount::query()->findOrFail((int) $request->input('account_id'));
        $teamId = (int) $request->user()->current_team_id;
        $created = [];
        $skipped = [];

        foreach ($files as $index => $file) {
            try {
                $created[] = $this->ingestUploadedFile($teamId, $account, $file);
            } catch (ValidationException $e) {
                $message = collect($e->errors())->flatten()->filter()->first();
                $label = $file->getClientOriginalName();
                $skipped[] = is_string($message) ? $label.': '.$message : $label;
                if (count($files) === 1) {
                    throw $e;
                }
            }
        }

        if ($created === []) {
            return back()->withErrors([
                'files' => $skipped[0] ?? __('No statements could be uploaded.'),
            ]);
        }

        $request->session()->put(self::QUEUE_SESSION_KEY, [
            'ids' => array_map(
                static fn (BankingStatementImport $import): int => (int) $import->id,
                $created
            ),
            'account_id' => (int) $account->id,
        ]);

        $redirect = $this->redirectToNextInQueue($request, $this->queueHasProfileMappedCsv($created));
        if ($skipped !== []) {
            $redirect->with('warning', __('Some files were skipped: :list', [
                'list' => implode(' ', $skipped),
            ]));
        }

        return $redirect;
    }

    public function map(Request $request, BankingStatementImport $import): Response|RedirectResponse
    {
        $this->authorizeTeam('banking.view');
        $this->authorizeImport($import);

        if (! in_array($import->file_type, ['csv', 'txt'], true)) {
            return redirect()->route('banking.import.preview', $import);
        }

        if (! in_array($import->status, [ImportStatus::Pending, ImportStatus::Parsed], true)) {
            return redirect()
                ->route('banking.imports.index')
                ->with('error', __('This import can no longer be remapped.'));
        }

        $preview = $this->csvImporter->preview($this->importService->absolutePath($import));
        $import->load('account');

        return Inertia::render('Banking/Import/MapCsv', [
            'bankImport' => $this->importPayload($import),
            'batch' => $this->batchMeta($request, $import),
            'headers' => $preview['headers'],
            'rows' => $preview['rows'],
            'delimiter' => $preview['delimiter'],
            'initialMapping' => $this->resolveInitialMapping($import, $preview['headers'], $preview['delimiter']),
            'mappingFields' => [
                ['key' => 'transaction_date', 'label' => 'Transaction date', 'required' => true],
                ['key' => 'description', 'label' => 'Description', 'required' => true],
                ['key' => 'amount', 'label' => 'Amount (signed)', 'required' => false],
                ['key' => 'debit', 'label' => 'Debit', 'required' => false],
                ['key' => 'credit', 'label' => 'Credit', 'required' => false],
                ['key' => 'reference', 'label' => 'Reference', 'required' => false],
                ['key' => 'value_date', 'label' => 'Value date', 'required' => false],
                ['key' => 'running_balance', 'label' => 'Running balance', 'required' => false],
            ],
        ]);
    }

    public function parseMapping(Request $request, BankingStatementImport $import): RedirectResponse
    {
        $this->authorizeTeam('banking.manage', $request);
        $this->authorizeImport($import);

        if (! in_array($import->status, [ImportStatus::Pending, ImportStatus::Parsed], true)) {
            return redirect()
                ->route('banking.imports.index')
                ->with('error', __('This import can no longer be remapped.'));
        }

        $validated = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.transaction_date' => ['required', 'string'],
            'mapping.description' => ['required', 'string'],
            'mapping.amount' => ['nullable', 'string'],
            'mapping.debit' => ['nullable', 'string'],
            'mapping.credit' => ['nullable', 'string'],
            'mapping.reference' => ['nullable', 'string'],
            'mapping.value_date' => ['nullable', 'string'],
            'mapping.running_balance' => ['nullable', 'string'],
            'mapping.date_format' => ['nullable', 'string'],
            'delimiter' => ['nullable', 'string', Rule::in([',', ';'])],
        ]);

        if (
            empty($validated['mapping']['amount'])
            && empty($validated['mapping']['debit'])
            && empty($validated['mapping']['credit'])
        ) {
            return back()->withErrors([
                'mapping' => __('Map a signed amount column or at least one of debit / credit.'),
            ]);
        }

        $preview = $this->csvImporter->preview($this->importService->absolutePath($import));
        $delimiter = $validated['delimiter'] ?? $preview['delimiter'];
        $parsed = $this->importService->parseImport($import, [
            'mapping' => $validated['mapping'],
            'delimiter' => $delimiter,
            'headers' => $preview['headers'],
        ]);

        $this->importService->saveCsvMappingProfile(
            $import->account,
            $preview['headers'],
            $delimiter,
            $validated['mapping']
        );

        $this->tryParseQueuedCsvsWithSavedProfile($request, $import->account);

        return $this->redirectToNextInQueue($request);
    }

    public function preview(Request $request, BankingStatementImport $import): Response|RedirectResponse
    {
        $this->authorizeTeam('banking.view', $request);
        $this->authorizeImport($import);

        if ($import->status === ImportStatus::Pending && in_array($import->file_type, ['csv', 'txt'], true)) {
            return redirect()->route('banking.import.map', $import);
        }

        if ($import->status === ImportStatus::Pending) {
            $parsed = $this->importService->parseImport($import);
            $summary = $this->importService->summarize($import, $parsed);
        } else {
            $parsed = null;
            $summary = $request->session()->get('summary', $import->metadata['summary'] ?? [
                'total' => $import->total_rows ?? 0,
                'new' => 0,
                'duplicates' => $import->duplicate_rows ?? 0,
                'errors' => 0,
                'preview' => $import->metadata['preview'] ?? [],
            ]);
        }

        if ($parsed !== null) {
            $summary = $this->importService->summarize($import, $parsed);
            $import->update([
                'metadata' => array_merge($import->metadata ?? [], [
                    'summary' => $summary,
                    'mapping' => $import->metadata['parsed']['mapping'] ?? ($import->metadata['mapping'] ?? null),
                ]),
            ]);
        }

        return Inertia::render('Banking/Import/Preview', [
            'bankImport' => $this->importPayload($import),
            'batch' => $this->batchMeta($request, $import),
            'summary' => $summary,
            'canConfirm' => $import->status === ImportStatus::Parsed,
            'canChangeMapping' => $import->status === ImportStatus::Parsed
                && in_array($import->file_type, ['csv', 'txt'], true),
            'mappingFromProfile' => (bool) $request->session()->get('mapping_from_profile', false),
        ]);
    }

    public function confirm(Request $request, BankingStatementImport $import): RedirectResponse
    {
        $this->authorizeTeam('banking.manage');
        $this->authorizeImport($import);

        if ($import->status !== ImportStatus::Parsed) {
            return redirect()
                ->route('banking.import.preview', $import)
                ->with('error', __('Import is not ready to confirm.'));
        }

        $options = $this->importService->parseOptionsFromMetadata($import);

        $parsed = $this->importService->parseImport($import, $options);
        $this->importService->confirmImport($import, $parsed);

        if (
            in_array($import->file_type, ['csv', 'txt'], true)
            && isset($options['mapping'], $options['headers'], $options['delimiter'])
        ) {
            $this->importService->saveCsvMappingProfile(
                $import->account,
                $options['headers'],
                (string) $options['delimiter'],
                $options['mapping']
            );
        }

        $this->tryParseQueuedCsvsWithSavedProfile($request, $import->account);

        return $this->redirectAfterConfirm($request, $import);
    }

    public function index(Request $request): Response
    {
        $this->authorizeTeam('banking.view', $request);
        $teamId = (int) $request->user()->current_team_id;
        $accountId = (int) $request->integer('account_id');
        $status = (string) $request->string('status')->toString();
        $allowedFilters = ['imported', 'not_imported', 'undone'];
        if ($status !== '' && ! in_array($status, $allowedFilters, true)) {
            $status = '';
        }
        $canManage = $request->user()?->canOnTeam('banking.manage') ?? false;

        $query = BankingStatementImport::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->with(['account:id,name,bank_name,currency']);

        if ($accountId > 0) {
            $query->where('account_id', $accountId);
        }
        if ($status !== '') {
            $query->whereIn(
                'status',
                array_map(fn (ImportStatus $case): string => $case->value, ImportStatus::forHistoryFilter($status))
            );
        }

        $imports = $query
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (BankingStatementImport $import): array => [
                'id' => $import->id,
                'original_filename' => $import->original_filename,
                'file_type' => $import->file_type,
                'status' => $import->status->historyGroup(),
                'status_label' => $import->status->historyLabel(),
                'total_rows' => $import->total_rows,
                'imported_rows' => $import->imported_rows,
                'duplicate_rows' => $import->duplicate_rows,
                'failed_rows' => $import->failed_rows,
                'can_undo' => $canManage && $import->status === ImportStatus::Imported,
                'can_reimport' => $canManage
                    && $import->status === ImportStatus::Undone
                    && $this->importService->hasStoredFile($import),
                'can_delete' => $canManage && $import->status->canPermanentlyDelete(),
                'created_at' => $import->created_at?->toIso8601String(),
                'updated_at' => $import->updated_at?->toIso8601String(),
                'account' => [
                    'id' => $import->account->id,
                    'name' => $import->account->name,
                    'bank_name' => $import->account->bank_name,
                    'currency' => $import->account->currency,
                ],
            ]);

        return Inertia::render('Banking/Import/History', [
            'imports' => $imports,
            'accounts' => $this->accountOptions(includeInactive: true),
            'filters' => [
                'account_id' => $accountId > 0 ? $accountId : null,
                'status' => $status !== '' ? $status : null,
            ],
            'status_options' => ImportStatus::historyFilterOptions(),
        ]);
    }

    public function undo(BankingStatementImport $import): RedirectResponse
    {
        $this->authorizeTeam('banking.manage');
        $this->authorizeImport($import);

        $this->importService->undoImport($import);

        return redirect()
            ->back()
            ->with('success', __('Import undone. Transactions were removed; the statement file was kept for re-import.'));
    }

    public function reimport(BankingStatementImport $import): RedirectResponse
    {
        $this->authorizeTeam('banking.manage');
        $this->authorizeImport($import);

        $result = $this->importService->reimport($import);
        $fresh = $result['import'];
        $parsed = $result['parsed'];

        if ($parsed === null) {
            return redirect()
                ->route('banking.import.map', $fresh)
                ->with('info', __('Map columns to continue re-importing this statement.'));
        }

        $redirect = redirect()->route('banking.import.preview', $fresh)
            ->with('summary', $this->importService->summarize($fresh, $parsed));

        if ($result['mapping_from_profile']) {
            $redirect->with('mapping_from_profile', true);
        }

        return $redirect->with('success', __('Statement restored. Review and confirm to import again.'));
    }

    public function destroy(BankingStatementImport $import): RedirectResponse
    {
        $this->authorizeTeam('banking.manage');
        $this->authorizeImport($import);

        $this->importService->deleteImport($import);

        return redirect()
            ->back()
            ->with('success', __('Statement file deleted.'));
    }

    /**
     * @return list<array{id: int, name: string, bank_name: string|null, currency: string}>
     */
    private function accountOptions(bool $includeInactive = false): array
    {
        $query = BankingAccount::query()->orderBy('name');

        if (! $includeInactive) {
            $query->where('is_active', true);
        }

        return $query
            ->get(['id', 'name', 'bank_name', 'currency'])
            ->map(fn (BankingAccount $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'bank_name' => $account->bank_name,
                'currency' => $account->currency,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function importPayload(BankingStatementImport $import): array
    {
        $import->load('account');

        return [
            'id' => $import->id,
            'original_filename' => $import->original_filename,
            'file_type' => $import->file_type,
            'status' => $import->status->value,
            'account' => [
                'id' => $import->account->id,
                'name' => $import->account->name,
                'currency' => $import->account->currency,
            ],
        ];
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string>
     */
    private function resolveInitialMapping(BankingStatementImport $import, array $headers, string $delimiter): array
    {
        $empty = [
            'transaction_date' => '',
            'description' => '',
            'amount' => '',
            'debit' => '',
            'credit' => '',
            'reference' => '',
            'value_date' => '',
            'running_balance' => '',
            'date_format' => '',
        ];

        $metadata = $import->metadata ?? [];
        $fromImport = $metadata['parsed']['mapping'] ?? ($metadata['mapping'] ?? null);
        if (is_array($fromImport)) {
            return array_merge($empty, array_intersect_key($fromImport, $empty));
        }

        $account = $import->account;
        $profile = $account?->csv_mapping_profile;
        if (
            $account !== null
            && is_array($profile)
            && is_array($profile['mapping'] ?? null)
            && $this->importService->profileMatches($account, $headers, $delimiter)
        ) {
            return array_merge($empty, array_intersect_key($profile['mapping'], $empty));
        }

        return $empty;
    }

    /**
     * @return list<string>
     */
    private function statementFileRules(): array
    {
        return [
            'file',
            'max:10240',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $value instanceof UploadedFile) {
                    return;
                }
                $ext = strtolower($value->getClientOriginalExtension());
                if (! in_array($ext, ['csv', 'txt', 'ofx'], true)) {
                    $fail(__('Only CSV, TXT, and OFX files are allowed.'));
                }
            },
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    private function uploadedStatementFiles(Request $request): array
    {
        $files = $request->file('files');
        if (is_array($files) && $files !== []) {
            return array_values(array_filter(
                $files,
                static fn (mixed $file): bool => $file instanceof UploadedFile
            ));
        }

        $single = $request->file('file');

        return $single instanceof UploadedFile ? [$single] : [];
    }

    private function ingestUploadedFile(int $teamId, BankingAccount $account, UploadedFile $file): BankingStatementImport
    {
        $import = $this->importService->storeUpload($teamId, $account, $file);
        $extension = strtolower((string) $import->file_type);

        if (in_array($extension, ['csv', 'txt'], true)) {
            $preview = $this->csvImporter->preview($this->importService->absolutePath($import));
            $account->refresh();

            if ($this->importService->profileMatches($account, $preview['headers'], $preview['delimiter'])) {
                $profile = $account->csv_mapping_profile ?? [];
                $this->importService->parseImport($import, [
                    'mapping' => $profile['mapping'] ?? [],
                    'delimiter' => $preview['delimiter'],
                    'headers' => $preview['headers'],
                ]);
            }

            return $import->fresh() ?? $import;
        }

        $this->importService->parseImport($import);

        return $import->fresh() ?? $import;
    }

    private function tryParseQueuedCsvsWithSavedProfile(Request $request, BankingAccount $account): void
    {
        $ids = $this->queueIds($request);
        if ($ids === []) {
            return;
        }

        $account->refresh();

        foreach ($ids as $id) {
            $queued = BankingStatementImport::queryWithoutTeamScope()->find($id);
            if (
                $queued === null
                || $queued->status !== ImportStatus::Pending
                || ! in_array($queued->file_type, ['csv', 'txt'], true)
            ) {
                continue;
            }

            $preview = $this->csvImporter->preview($this->importService->absolutePath($queued));
            if (! $this->importService->profileMatches($account, $preview['headers'], $preview['delimiter'])) {
                continue;
            }

            $profile = $account->csv_mapping_profile ?? [];
            $this->importService->parseImport($queued, [
                'mapping' => $profile['mapping'] ?? [],
                'delimiter' => $preview['delimiter'],
                'headers' => $preview['headers'],
            ]);
        }
    }

    private function redirectToNextInQueue(Request $request, bool $mappingFromProfile = false): RedirectResponse
    {
        $nextMap = $this->firstQueuedImport($request, ImportStatus::Pending);
        if ($nextMap !== null) {
            return redirect()->route('banking.import.map', $nextMap);
        }

        $nextPreview = $this->firstQueuedImport($request, ImportStatus::Parsed);
        if ($nextPreview !== null) {
            $options = $this->importService->parseOptionsFromMetadata($nextPreview);
            $parsed = $this->importService->parseImport($nextPreview, $options);
            $redirect = redirect()->route('banking.import.preview', $nextPreview)
                ->with('summary', $this->importService->summarize($nextPreview, $parsed));

            if ($mappingFromProfile && in_array($nextPreview->file_type, ['csv', 'txt'], true)) {
                $redirect->with('mapping_from_profile', true);
            }

            return $redirect;
        }

        $accountId = $request->session()->get(self::QUEUE_SESSION_KEY.'.account_id');
        $request->session()->forget(self::QUEUE_SESSION_KEY);

        return redirect()->route('banking.transactions.index', [
            'account_id' => $accountId,
        ]);
    }

    private function redirectAfterConfirm(Request $request, BankingStatementImport $import): RedirectResponse
    {
        $ids = $this->queueIds($request);
        if ($ids === [] || ! in_array((int) $import->id, $ids, true)) {
            return redirect()
                ->route('banking.transactions.index', [
                    'account_id' => $import->account_id,
                ])
                ->with('success', __('Bank statement imported successfully.'));
        }

        $nextMap = $this->firstQueuedImport($request, ImportStatus::Pending);
        if ($nextMap !== null) {
            return redirect()->route('banking.import.map', $nextMap)
                ->with('success', __(':file imported. Map the next statement.', [
                    'file' => $import->original_filename,
                ]));
        }

        $nextPreview = $this->firstQueuedImport($request, ImportStatus::Parsed);
        if ($nextPreview !== null) {
            $options = $this->importService->parseOptionsFromMetadata($nextPreview);
            $parsed = $this->importService->parseImport($nextPreview, $options);

            return redirect()->route('banking.import.preview', $nextPreview)
                ->with('summary', $this->importService->summarize($nextPreview, $parsed))
                ->with('success', __(':file imported. Review the next statement.', [
                    'file' => $import->original_filename,
                ]));
        }

        $count = count($ids);
        $request->session()->forget(self::QUEUE_SESSION_KEY);

        return redirect()
            ->route('banking.transactions.index', [
                'account_id' => $import->account_id,
            ])
            ->with('success', $count > 1
                ? __(':count bank statements imported successfully.', ['count' => $count])
                : __('Bank statement imported successfully.')
            );
    }

    /**
     * @return list<int>
     */
    private function queueIds(Request $request): array
    {
        $ids = $request->session()->get(self::QUEUE_SESSION_KEY.'.ids', []);
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_map('intval', $ids));
    }

    private function firstQueuedImport(Request $request, ImportStatus $status): ?BankingStatementImport
    {
        foreach ($this->queueIds($request) as $id) {
            $queued = BankingStatementImport::queryWithoutTeamScope()->find($id);
            if ($queued !== null && $queued->status === $status) {
                return $queued;
            }
        }

        return null;
    }

    /**
     * @return array{index: int, total: int}|null
     */
    private function batchMeta(Request $request, BankingStatementImport $import): ?array
    {
        $ids = $this->queueIds($request);
        if (count($ids) < 2) {
            return null;
        }

        $position = array_search((int) $import->id, $ids, true);
        if ($position === false) {
            return null;
        }

        return [
            'index' => $position + 1,
            'total' => count($ids),
        ];
    }

    /**
     * @param  list<BankingStatementImport>  $imports
     */
    private function queueHasProfileMappedCsv(array $imports): bool
    {
        foreach ($imports as $import) {
            if (
                $import->status === ImportStatus::Parsed
                && in_array($import->file_type, ['csv', 'txt'], true)
            ) {
                return true;
            }
        }

        return false;
    }

    private function authorizeImport(BankingStatementImport $import): void
    {
        abort_unless(
            $import->team_id === (int) request()->user()->current_team_id,
            403
        );
    }
}
