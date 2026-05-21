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
        return Inertia::render('Banking/Import/Upload', [
            'accounts' => $this->accountOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
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
            return redirect()->route('banking.import.map', $import);
        }

        $parsed = $this->importService->parseImport($import);

        return redirect()->route('banking.import.preview', $import)
            ->with('summary', $this->importService->summarize($import, $parsed));
    }

    public function map(BankingStatementImport $import): Response
    {
        $this->authorizeImport($import);

        $preview = $this->csvImporter->preview($this->importService->absolutePath($import));

        return Inertia::render('Banking/Import/MapCsv', [
            'bankImport' => $this->importPayload($import),
            'headers' => $preview['headers'],
            'rows' => $preview['rows'],
            'delimiter' => $preview['delimiter'],
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
        $this->authorizeImport($import);

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
        $parsed = $this->importService->parseImport($import, [
            'mapping' => $validated['mapping'],
            'delimiter' => $validated['delimiter'] ?? $preview['delimiter'],
            'headers' => $preview['headers'],
        ]);

        return redirect()->route('banking.import.preview', $import)
            ->with('summary', $this->importService->summarize($import, $parsed));
    }

    public function preview(Request $request, BankingStatementImport $import): Response|RedirectResponse
    {
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
        ]);
    }

    public function confirm(BankingStatementImport $import): RedirectResponse
    {
        $this->authorizeImport($import);

        if ($import->status !== ImportStatus::Parsed) {
            return redirect()
                ->route('banking.import.preview', $import)
                ->with('error', __('Import is not ready to confirm.'));
        }

        $options = [];
        $metadata = $import->metadata ?? [];
        if (isset($metadata['parsed']['mapping'])) {
            $options['mapping'] = $metadata['parsed']['mapping'];
        } elseif (isset($metadata['mapping'])) {
            $options['mapping'] = $metadata['mapping'];
        }
        if (isset($metadata['parsed']['delimiter'])) {
            $options['delimiter'] = $metadata['parsed']['delimiter'];
        }
        if (isset($metadata['parsed']['headers'])) {
            $options['headers'] = $metadata['parsed']['headers'];
        }

        $parsed = $this->importService->parseImport($import, $options);
        $this->importService->confirmImport($import, $parsed);

        return redirect()
            ->route('banking.transactions.index', [
                'account_id' => $import->account_id,
            ])
            ->with('success', __('Bank statement imported successfully.'));
    }

    /**
     * @return list<array{id: int, name: string, bank_name: string|null, currency: string}>
     */
    private function accountOptions(): array
    {
        return BankingAccount::query()
            ->where('is_active', true)
            ->orderBy('name')
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

    private function authorizeImport(BankingStatementImport $import): void
    {
        abort_unless(
            $import->team_id === (int) request()->user()->current_team_id,
            403
        );
    }
}
