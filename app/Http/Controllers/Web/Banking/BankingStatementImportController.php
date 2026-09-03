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
use Inertia\Inertia;
use Inertia\Response;

class BankingStatementImportController extends Controller
{
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

        $validated = $request->validate([
            'account_id' => [
                'required',
                Rule::exists('banking_accounts', 'id')->where(
                    'team_id',
                    $request->user()->current_team_id
                ),
            ],
            'file' => [
                'required',
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
            ],
        ]);

        $account = BankingAccount::query()->findOrFail($validated['account_id']);
        $import = $this->importService->storeUpload(
            (int) $request->user()->current_team_id,
            $account,
            $validated['file']
        );

        $extension = strtolower((string) $import->file_type);
        if (in_array($extension, ['csv', 'txt'], true)) {
            $preview = $this->csvImporter->preview($this->importService->absolutePath($import));
            $account->refresh();

            if ($this->importService->profileMatches($account, $preview['headers'], $preview['delimiter'])) {
                $profile = $account->csv_mapping_profile ?? [];
                $parsed = $this->importService->parseImport($import, [
                    'mapping' => $profile['mapping'] ?? [],
                    'delimiter' => $preview['delimiter'],
                    'headers' => $preview['headers'],
                ]);

                return redirect()->route('banking.import.preview', $import)
                    ->with('summary', $this->importService->summarize($import, $parsed))
                    ->with('mapping_from_profile', true);
            }

            return redirect()->route('banking.import.map', $import);
        }

        $parsed = $this->importService->parseImport($import);

        return redirect()->route('banking.import.preview', $import)
            ->with('summary', $this->importService->summarize($import, $parsed));
    }

    public function map(BankingStatementImport $import): Response|RedirectResponse
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

        return redirect()->route('banking.import.preview', $import)
            ->with('summary', $this->importService->summarize($import, $parsed));
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
            'summary' => $summary,
            'canConfirm' => $import->status === ImportStatus::Parsed,
            'canChangeMapping' => $import->status === ImportStatus::Parsed
                && in_array($import->file_type, ['csv', 'txt'], true),
            'mappingFromProfile' => (bool) $request->session()->get('mapping_from_profile', false),
        ]);
    }

    public function confirm(BankingStatementImport $import): RedirectResponse
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

        return redirect()
            ->route('banking.transactions.index', [
                'account_id' => $import->account_id,
            ])
            ->with('success', __('Bank statement imported successfully.'));
    }

    public function index(Request $request): Response
    {
        $this->authorizeTeam('banking.view', $request);
        $teamId = (int) $request->user()->current_team_id;
        $accountId = (int) $request->integer('account_id');
        $status = (string) $request->string('status')->toString();
        $allowedStatuses = array_map(fn (ImportStatus $case) => $case->value, ImportStatus::cases());
        if ($status !== '' && ! in_array($status, $allowedStatuses, true)) {
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
            $query->where('status', $status);
        }

        $imports = $query
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (BankingStatementImport $import): array => [
                'id' => $import->id,
                'original_filename' => $import->original_filename,
                'file_type' => $import->file_type,
                'status' => $import->status->value,
                'status_label' => $import->status->label(),
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
            'status_options' => array_map(
                fn (ImportStatus $case): array => [
                    'value' => $case->value,
                    'label' => $case->label(),
                ],
                ImportStatus::cases()
            ),
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

    private function authorizeImport(BankingStatementImport $import): void
    {
        abort_unless(
            $import->team_id === (int) request()->user()->current_team_id,
            403
        );
    }
}
