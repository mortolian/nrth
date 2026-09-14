<?php

namespace App\Http\Controllers\Web;

use App\Domain\Accounting\Actions\DeleteTransactionAction;
use App\Domain\Accounting\Actions\PostTransactionAction;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\TaxLine;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Actions\EnsureDefaultBankingAccount;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Support\BankingPaymentAccounts;
use App\Domain\Expenses\Actions\CreateExpenseAction;
use App\Domain\Expenses\DTOs\CreateExpenseDTO;
use App\Domain\Expenses\Services\ExpenseBooking;
use App\Domain\Expenses\Services\ParseExpenseReceipt;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Database\Seeders\DefaultTaxRatesSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ExpensesController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeTeam('expenses.view', $request);

        if (! Schema::hasTable('transactions')) {
            return Inertia::render('Expenses/Index', [
                'expenses' => new LengthAwarePaginator([], 0, 15),
                'summary' => [
                    'total_this_month' => 0,
                    'total_vat_claimable' => 0,
                    'awaiting_receipts' => 0,
                ],
                'categories' => [],
                'filters' => $this->filters($request),
            ]);
        }

        $teamId = (int) $request->user()->current_team_id;
        $filters = $this->rememberedIndexFilters($request, $teamId);
        $start = (string) ($filters['from'] ?? '');
        $end = (string) ($filters['to'] ?? '');
        $categoryList = $filters['categories'];
        $supplier = trim((string) ($filters['supplier'] ?? ''));
        $hasReceipt = (string) $filters['has_receipt'];
        $vatStatus = (string) $filters['vat_status'];

        $query = Transaction::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('type', TransactionType::Expense->value)
            ->with(['journalEntries.account', 'taxLines', 'supplier'])
            ->withCount('media');

        if ($start !== '') {
            $query->whereDate('transaction_date', '>=', $start);
        }
        if ($end !== '') {
            $query->whereDate('transaction_date', '<=', $end);
        }
        if ($supplier !== '') {
            $pattern = '%'.mb_strtolower($supplier).'%';
            $query->where(function ($q) use ($pattern): void {
                $q->whereRaw('LOWER(reference) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$pattern])
                    ->orWhereHas('supplier', fn ($sq) => $sq->whereRaw('LOWER(name) LIKE ?', [$pattern]));
            });
        }
        if ($hasReceipt === 'yes') {
            $query->has('media');
        } elseif ($hasReceipt === 'no') {
            $query->doesntHave('media');
        }
        if (! empty($categoryList)) {
            $query->whereHas('journalEntries.account', fn ($q) => $q
                ->where('type', AccountType::Expense->value)
                ->whereIn('name', $categoryList));
        }
        if ($vatStatus === 'claimable') {
            $query->whereHas('taxLines', fn ($q) => $q->where('tax_amount_cents', '>', 0));
        } elseif ($vatStatus === 'non_claimable') {
            $query->whereDoesntHave('taxLines', fn ($q) => $q->where('tax_amount_cents', '>', 0));
        }

        $expenses = $query
            ->orderByDesc('transaction_date')
            ->paginate(15, ['*'], 'page', $filters['page'])
            ->withQueryString()
            ->through(function (Transaction $transaction): array {
                $expenseLines = $transaction->journalEntries->filter(
                    fn ($entry) => $entry->account?->type === AccountType::Expense
                );
                $amountCents = (int) $expenseLines->sum(fn ($line) => (int) $line->getRawOriginal('amount_cents'));
                $category = (string) ($expenseLines->first()?->account?->name ?? 'Uncategorized');
                $vatAmount = $this->expenseVatAmountCents($transaction);

                return [
                    'id' => $transaction->id,
                    'date' => optional($transaction->transaction_date)->toDateString(),
                    'supplier_id' => $transaction->supplier_id,
                    'supplier' => $transaction->supplier?->name
                        ?: ($transaction->reference ?: ($transaction->description ?: 'Unknown supplier')),
                    'category' => $category,
                    'description' => $transaction->description,
                    'amount' => $amountCents,
                    'vat_amount' => $vatAmount,
                    'total' => $amountCents + $vatAmount,
                    'status' => $transaction->status->value,
                    'has_receipt' => $transaction->media_count > 0,
                    'can_delete' => DeleteTransactionAction::canDelete($transaction),
                ];
            });

        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $monthRows = Transaction::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('type', TransactionType::Expense->value)
            ->whereBetween('transaction_date', [$monthStart, $monthEnd])
            ->with(['journalEntries.account', 'taxLines'])
            ->withCount('media')
            ->get();

        $totalThisMonth = $monthRows->sum(function (Transaction $transaction): int {
            $exclCents = (int) $transaction->journalEntries
                ->filter(fn ($entry) => $entry->account?->type === AccountType::Expense)
                ->sum(fn ($entry) => (int) $entry->getRawOriginal('amount_cents'));

            return $exclCents + $this->expenseVatAmountCents($transaction);
        });
        $totalVat = (int) $monthRows->sum(fn (Transaction $t): int => $this->expenseVatAmountCents($t));
        $awaitingReceipts = $monthRows->filter(fn (Transaction $t): bool => (int) $t->media_count === 0)->count();

        $categories = Transaction::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('type', TransactionType::Expense->value)
            ->with('journalEntries.account')
            ->get()
            ->flatMap(fn (Transaction $transaction) => $transaction->journalEntries
                ->filter(fn ($entry) => $entry->account?->type === AccountType::Expense)
                ->map(fn ($entry) => $entry->account?->name))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses,
            'summary' => [
                'total_this_month' => $totalThisMonth,
                'total_vat_claimable' => $totalVat,
                'awaiting_receipts' => $awaitingReceipts,
            ],
            'categories' => $categories,
            'filters' => [
                'from' => $filters['from'],
                'to' => $filters['to'],
                'categories' => $filters['categories'],
                'supplier' => $filters['supplier'],
                'has_receipt' => $filters['has_receipt'],
                'vat_status' => $filters['vat_status'],
            ],
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $this->authorizeTeam('expenses.view', $request);
        $teamId = (int) $request->user()->current_team_id;

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $validated['ids'])));

        $transactions = Transaction::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('type', TransactionType::Expense->value)
            ->whereIn('id', $ids)
            ->with(['journalEntries.account', 'taxLines', 'supplier'])
            ->withCount('media')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get();

        $filename = 'expenses-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($transactions): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            // UTF-8 BOM so Excel / Sheets detect encoding correctly.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Date',
                'Supplier',
                'Category',
                'Description',
                'Total (ZAR)',
                'Amount excl VAT (ZAR)',
                'VAT amount (ZAR)',
                'Status',
                'Has receipt',
                'Reference',
            ]);

            foreach ($transactions as $transaction) {
                $expenseLines = $transaction->journalEntries->filter(
                    fn ($entry) => $entry->account?->type === AccountType::Expense
                );
                $amountCents = (int) $expenseLines->sum(fn ($line) => (int) $line->getRawOriginal('amount_cents'));
                $vatCents = $this->expenseVatAmountCents($transaction);
                $category = (string) ($expenseLines->first()?->account?->name ?? 'Uncategorized');
                $supplier = $transaction->supplier?->name
                    ?: ($transaction->reference ?: ($transaction->description ?: 'Unknown supplier'));
                $meta = $transaction->expense_meta ?? [];

                fputcsv($handle, [
                    optional($transaction->transaction_date)->toDateString() ?? '',
                    $supplier,
                    $category,
                    (string) ($transaction->description ?? ''),
                    number_format(($amountCents + $vatCents) / 100, 2, '.', ''),
                    number_format($amountCents / 100, 2, '.', ''),
                    number_format($vatCents / 100, 2, '.', ''),
                    $transaction->status->value,
                    ((int) $transaction->media_count) > 0 ? 'yes' : 'no',
                    (string) ($meta['external_reference'] ?? ''),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeTeam('expenses.manage', $request);
        $team = $request->user()?->currentTeam;
        abort_if($team === null, 403);
        (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);
        (new DefaultTaxRatesSeeder)->runForTeam($team);
        (new EnsureDefaultBankingAccount)->execute($team);

        $teamId = (int) $team->id;
        $prefillSupplierId = (int) $request->integer('supplier_id');
        $prefillSupplierCustom = trim((string) $request->string('supplier')->toString());

        return Inertia::render('Expenses/Form', [
            'isEditing' => false,
            'expense' => null,
            'prefill' => [
                'supplier_id' => $prefillSupplierId > 0 ? $prefillSupplierId : 0,
                'supplier_custom' => $prefillSupplierId > 0 ? '' : $prefillSupplierCustom,
            ],
            ...$this->expenseFormSharedProps($teamId),
        ]);
    }

    public function edit(Request $request, Transaction $transaction): Response
    {
        $this->authorizeTeam('expenses.manage', $request);
        $transaction = $this->resolveTeamExpense($request, $transaction);
        $team = $request->user()?->currentTeam;
        abort_if($team === null, 403);
        (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);
        (new DefaultTaxRatesSeeder)->runForTeam($team);
        (new EnsureDefaultBankingAccount)->execute($team);

        $teamId = (int) $team->id;
        $user = $request->user();

        return Inertia::render('Expenses/Form', [
            'isEditing' => true,
            'expense' => $this->serializeExpenseForForm(
                $transaction,
                $team,
                $user instanceof User ? $user : null,
            ),
            'prefill' => null,
            ...$this->expenseFormSharedProps($teamId),
        ]);
    }

    public function parseReceipt(Request $request, ParseExpenseReceipt $parser): JsonResponse
    {
        $this->authorizeTeam('expenses.manage', $request);
        $team = $request->user()?->currentTeam;
        abort_if($team === null, 403);

        if (! $parser->enabledFor($team)) {
            throw ValidationException::withMessages([
                'receipt' => __('AI is not configured. Add an API key in Business settings → AI.'),
            ]);
        }

        $files = $this->resolveReceiptParseFiles($request);
        $parsed = $parser->parseMany($files, $team);

        return response()->json([
            'data' => $parsed->toFormPayload(),
        ]);
    }

    /**
     * @return list<UploadedFile>
     */
    private function resolveReceiptParseFiles(Request $request): array
    {
        if ($request->has('attachment_id') && $request->string('attachment_id')->toString() === '') {
            $request->merge(['attachment_id' => null]);
        }
        if ($request->has('transaction_id') && $request->string('transaction_id')->toString() === '') {
            $request->merge(['transaction_id' => null]);
        }

        $request->validate([
            'receipt' => ['nullable', 'file', 'max:10240', 'mimes:jpeg,jpg,png,gif,webp,pdf'],
            'receipts' => ['nullable', 'array', 'max:10'],
            'receipts.*' => ['file', 'max:10240', 'mimes:jpeg,jpg,png,gif,webp,pdf'],
            'attachment_id' => ['nullable', 'integer'],
            'attachment_ids' => ['nullable', 'array', 'max:10'],
            'attachment_ids.*' => ['integer'],
            'transaction_id' => ['nullable', 'integer'],
        ]);

        $files = [];

        if ($request->hasFile('receipt')) {
            /** @var UploadedFile $receipt */
            $receipt = $request->file('receipt');
            $files[] = $receipt;
        }

        if ($request->hasFile('receipts')) {
            $uploaded = $request->file('receipts');
            $receipts = is_array($uploaded) ? $uploaded : [$uploaded];
            foreach ($receipts as $receipt) {
                if ($receipt instanceof UploadedFile) {
                    $files[] = $receipt;
                }
            }
        }

        $attachmentIds = [];
        if ($request->filled('attachment_id')) {
            $attachmentIds[] = (int) $request->integer('attachment_id');
        }
        if ($request->filled('attachment_ids')) {
            foreach ((array) $request->input('attachment_ids', []) as $id) {
                $attachmentIds[] = (int) $id;
            }
        }
        $attachmentIds = array_values(array_unique(array_filter($attachmentIds)));

        if ($attachmentIds !== []) {
            $transactionId = (int) $request->integer('transaction_id');
            if ($transactionId <= 0) {
                throw ValidationException::withMessages([
                    'transaction_id' => __('Choose the expense these receipts belong to.'),
                ]);
            }

            $transaction = Transaction::queryWithoutTeamScope()->findOrFail($transactionId);
            $this->resolveTeamExpense($request, $transaction);

            foreach ($attachmentIds as $attachmentId) {
                $media = Media::query()->findOrFail($attachmentId);
                $media = $this->resolveExpenseAttachment($transaction, $media);
                $path = $media->getPath();
                if (! is_string($path) || $path === '' || ! is_file($path)) {
                    throw ValidationException::withMessages([
                        'attachment_id' => __('Could not read that receipt file.'),
                    ]);
                }

                $files[] = new UploadedFile(
                    $path,
                    $media->file_name ?: 'receipt',
                    $media->mime_type ?: null,
                    null,
                    true,
                );
            }
        }

        if ($files === []) {
            throw ValidationException::withMessages([
                'receipt' => __('Upload or select at least one receipt to scan.'),
            ]);
        }

        if (count($files) > 10) {
            throw ValidationException::withMessages([
                'receipt' => __('You can scan at most 10 receipts at once.'),
            ]);
        }

        return $files;
    }

    public function store(Request $request, CreateExpenseAction $createExpenseAction): RedirectResponse
    {
        $this->authorizeTeam('expenses.manage', $request);
        $team = $request->user()?->currentTeam;
        abort_if($team === null, 403);
        (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);
        (new DefaultTaxRatesSeeder)->runForTeam($team);
        (new EnsureDefaultBankingAccount)->execute($team);

        $teamId = (int) $team->id;
        $userId = (int) $request->user()->id;
        $payload = $this->validatedExpensePayload($request, $teamId);
        $transaction = $createExpenseAction->execute(CreateExpenseDTO::fromValidated($teamId, $userId, $payload));

        if ($request->hasFile('receipts') || $request->hasFile('receipt')) {
            $this->attachReceiptUploads($request, $transaction);
        }

        return to_route('expenses.index');
    }

    public function update(Request $request, Transaction $transaction, PostTransactionAction $postTransactionAction, ExpenseBooking $booking): RedirectResponse
    {
        $this->authorizeTeam('expenses.manage', $request);
        $transaction = $this->resolveTeamExpense($request, $transaction);
        $team = $request->user()?->currentTeam;
        abort_if($team === null, 403);
        (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);
        (new DefaultTaxRatesSeeder)->runForTeam($team);
        (new EnsureDefaultBankingAccount)->execute($team);

        $teamId = (int) $team->id;

        $payload = $this->validatedExpensePayload($request, $teamId);
        $categoryAccount = $booking->resolveCategoryAccount($teamId, $payload);
        $booking->assertCategoryRules($categoryAccount, $payload);

        [$reference, $supplierIdToSave] = $booking->resolveSupplier($payload, $teamId);
        $normalized = $booking->normalizedExpenseAmounts($categoryAccount, $payload);
        $amountExclCents = $normalized[0];
        $vatAmountCents = $normalized[1];
        $vatRate = $normalized[2];
        $isVatClaimable = $normalized[3];

        $bankingAccount = $booking->resolvePaidFromBankingAccount($teamId, (int) $payload['paid_from_banking_account_id']);
        $creditAccount = $bankingAccount->glAccount;
        abort_if($creditAccount === null, 422);

        $vatInputAccount = Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('code', '1200')
            ->first();

        $expenseMeta = $booking->buildExpenseMeta($categoryAccount, $payload, $bankingAccount, $creditAccount);

        DB::transaction(function () use ($transaction, $payload, $teamId, $categoryAccount, $creditAccount, $vatInputAccount, $isVatClaimable, $amountExclCents, $vatAmountCents, $vatRate, $postTransactionAction, $supplierIdToSave, $reference, $expenseMeta, $booking): void {
            $transaction->forceFill([
                'status' => TransactionStatus::Draft,
                'supplier_id' => $supplierIdToSave,
                'reference' => $reference,
                'description' => $booking->expenseDescriptionFromPayload($payload),
                'expense_meta' => $expenseMeta,
                'transaction_date' => $payload['date'],
            ]);
            $transaction->save();

            JournalEntry::query()->where('transaction_id', $transaction->id)->delete();
            TaxLine::query()->where('transaction_id', $transaction->id)->delete();

            $booking->writeExpenseJournalAndTax(
                $transaction,
                $teamId,
                $payload,
                $categoryAccount,
                $creditAccount,
                $vatInputAccount,
                $isVatClaimable,
                $amountExclCents,
                $vatAmountCents,
                $vatRate,
                $reference
            );

            $postTransactionAction->execute($transaction->fresh());
        });

        if ($request->hasFile('receipts') || $request->hasFile('receipt')) {
            $this->attachReceiptUploads($request, $transaction);
        }

        $this->detachReceiptAttachments($request, $transaction);

        return to_route('expenses.index');
    }

    public function destroy(Request $request, Transaction $transaction, DeleteTransactionAction $deleteTransactionAction): RedirectResponse
    {
        $this->authorizeTeam('expenses.delete', $request);
        $transaction = $this->resolveTeamExpense($request, $transaction);

        try {
            $deleteTransactionAction->execute($transaction);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return to_route('expenses.index')->with('success', __('Expense deleted.'));
    }

    public function storeReceipt(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorizeTeam('expenses.manage', $request);
        $transaction = $this->resolveTeamExpense($request, $transaction);
        $request->validate([
            'receipt' => ['nullable', 'file', 'max:10240', 'mimes:jpeg,jpg,png,gif,webp,pdf'],
            'receipts' => ['nullable', 'array', 'max:20'],
            'receipts.*' => ['file', 'max:10240', 'mimes:jpeg,jpg,png,gif,webp,pdf'],
        ]);

        if (! $request->hasFile('receipts') && ! $request->hasFile('receipt')) {
            throw ValidationException::withMessages([
                'receipts' => __('Attach at least one receipt file.'),
            ]);
        }

        try {
            $attached = $this->attachReceiptUploads($request, $transaction);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', __('Could not attach that receipt. Please try again.'));
        }

        if ($attached < 1) {
            return back()->with('error', __('Could not attach that receipt. Please try again.'));
        }

        $message = $attached === 1
            ? __('Receipt attached successfully.')
            : __(':count receipts attached successfully.', ['count' => $attached]);

        return back()->with('success', $message);
    }

    public function showAttachment(Request $request, Transaction $transaction, Media $media): BinaryFileResponse
    {
        $this->authorizeTeam('expenses.view', $request);
        $transaction = $this->resolveTeamExpense($request, $transaction);
        $media = $this->resolveExpenseAttachment($transaction, $media);

        $path = $media->getPath();
        abort_unless(is_string($path) && $path !== '' && is_file($path), 404);

        $filename = str_replace(["\r", "\n", '"'], '', (string) $media->file_name) ?: 'receipt';

        return response()->file($path, [
            'Content-Type' => $media->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroyAttachment(Request $request, Transaction $transaction, Media $media): RedirectResponse
    {
        $this->authorizeTeam('expenses.manage', $request);
        $transaction = $this->resolveTeamExpense($request, $transaction);
        $media = $this->resolveExpenseAttachment($transaction, $media);
        $media->delete();

        return back();
    }

    private function resolveExpenseAttachment(Transaction $transaction, Media $media): Media
    {
        abort_unless($media->model_type === $transaction->getMorphClass(), 404);
        abort_unless((int) $media->model_id === (int) $transaction->id, 404);
        abort_unless($media->collection_name === 'attachments', 404);

        return $media;
    }

    private function attachReceiptUploads(Request $request, Transaction $transaction): int
    {
        $files = [];

        if ($request->hasFile('receipts')) {
            $uploaded = $request->file('receipts');
            $files = is_array($uploaded) ? $uploaded : [$uploaded];
        }

        if ($request->hasFile('receipt')) {
            $files[] = $request->file('receipt');
        }

        $attached = 0;
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            $this->assertReceiptFileIsSafe($file);

            $transaction
                ->addMedia($file)
                ->usingFileName($this->safeReceiptFileName($file))
                ->toMediaCollection('attachments');
            $attached++;
        }

        return $attached;
    }

    private function detachReceiptAttachments(Request $request, Transaction $transaction): void
    {
        $ids = $request->input('remove_attachment_ids', []);
        if (! is_array($ids) || $ids === []) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return;
        }

        foreach ($ids as $mediaId) {
            $media = Media::query()->find($mediaId);
            if ($media === null) {
                continue;
            }

            if (
                $media->model_type !== $transaction->getMorphClass()
                || (int) $media->model_id !== (int) $transaction->id
                || $media->collection_name !== 'attachments'
            ) {
                continue;
            }

            $media->delete();
        }
    }

    private function assertReceiptFileIsSafe(UploadedFile $file): void
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw ValidationException::withMessages([
                'receipt' => __('Could not read that receipt file.'),
            ]);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages([
                'receipt' => __('Could not read that receipt file.'),
            ]);
        }

        $head = (string) fread($handle, 128);
        fclose($handle);

        if (preg_match('/^\s*(<!DOCTYPE|<html\b|SQLSTATE|Illuminate\\\\)/i', $head) === 1) {
            throw ValidationException::withMessages([
                'receipt' => __('That file does not look like a receipt image or PDF.'),
            ]);
        }
    }

    private function safeReceiptFileName(UploadedFile $file): string
    {
        $original = $file->getClientOriginalName() ?: 'receipt';
        $basename = pathinfo($original, PATHINFO_FILENAME) ?: 'receipt';
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', $basename) ?: 'receipt';
        $safeBase = trim($safeBase, '.-_') ?: 'receipt';

        if ($extension !== '') {
            return $safeBase.'.'.$extension;
        }

        return $safeBase;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedExpensePayload(Request $request, int $teamId): array
    {
        if ($request->has('supplier_id') && $request->string('supplier_id')->toString() === '') {
            $request->merge(['supplier_id' => null]);
        }

        return $request->validate([
            'date' => ['required', 'date'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('team_id', $teamId)],
            'supplier' => ['required_without:supplier_id', 'nullable', 'string', 'max:255'],
            'category_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('team_id', $teamId)],
            'description' => ['nullable', 'string'],
            'amount_excl_vat_cents' => ['required', 'integer', 'min:0'],
            'vat_rate' => ['required', Rule::in(['vat15', 'vat0', 'exempt', 'no_vat'])],
            'vat_amount_cents' => ['required', 'integer', 'min:0'],
            'paid_from_banking_account_id' => [
                'required',
                'integer',
                Rule::exists('banking_accounts', 'id')->where(function ($query) use ($teamId): void {
                    $query->where('team_id', $teamId)
                        ->where('is_active', true)
                        ->whereNotNull('gl_account_id');
                }),
            ],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'office_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'distance_km' => ['nullable', 'numeric', 'min:0'],
            'rate_per_km' => ['nullable', 'numeric', 'min:0'],
            'receipt' => ['nullable', 'file', 'max:10240', 'mimes:jpeg,jpg,png,gif,webp,pdf'],
            'receipts' => ['nullable', 'array', 'max:20'],
            'receipts.*' => ['file', 'max:10240', 'mimes:jpeg,jpg,png,gif,webp,pdf'],
            'remove_attachment_ids' => ['nullable', 'array', 'max:50'],
            'remove_attachment_ids.*' => ['integer'],
        ]);
    }

    /**
     * @return array{
     *   categories: list<array{id: int, name: string}>,
     *   paid_from_options: list<array{id: int, name: string, gl_account_id: int, gl_label: string}>,
     *   supplier_options: list<array{id: int, name: string}>,
     *   tax_rates: list<array{value: string, label: string, rate: float, claimable: bool}>,
     *   sars_rate_per_km: float
     * }
     */
    private function expenseFormSharedProps(int $teamId): array
    {
        return [
            'categories' => Account::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('type', AccountType::Expense->value)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'name' => trim($account->code.' - '.$account->name),
                ])
                ->all(),
            'paid_from_options' => BankingPaymentAccounts::forExpensePaidFrom($teamId),
            'supplier_options' => Supplier::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Supplier $supplier) => [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                ])
                ->all(),
            'tax_rates' => [
                ['value' => 'vat15', 'label' => 'VAT 15%', 'rate' => 0.15, 'claimable' => true],
                ['value' => 'vat0', 'label' => 'VAT 0%', 'rate' => 0.0, 'claimable' => true],
                ['value' => 'exempt', 'label' => 'Exempt', 'rate' => 0.0, 'claimable' => false],
                ['value' => 'no_vat', 'label' => 'No VAT', 'rate' => 0.0, 'claimable' => false],
            ],
            'sars_rate_per_km' => 4.84,
        ];
    }

    private function expenseVatAmountCents(Transaction $transaction): int
    {
        $fromTaxLines = (int) $transaction->taxLines->sum('tax_amount_cents');
        if ($fromTaxLines > 0) {
            return $fromTaxLines;
        }

        // Legacy expenses may have posted VAT to 1200 without a tax_lines row.
        return (int) $transaction->journalEntries
            ->filter(fn ($entry) => $entry->type === EntryType::Debit && $entry->account?->code === '1200')
            ->sum(fn ($entry) => (int) $entry->getRawOriginal('amount_cents'));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeExpenseForForm(Transaction $transaction, Team $team, ?User $user = null): array
    {
        $transaction->loadMissing(['journalEntries.account', 'taxLines', 'supplier']);

        $expenseLine = $transaction->journalEntries->first(
            fn ($entry) => $entry->account?->type === AccountType::Expense
        );
        $creditLine = $transaction->journalEntries->first(
            fn ($entry) => $entry->type === EntryType::Credit
        );

        $amountExclCents = $expenseLine !== null
            ? (int) $expenseLine->getRawOriginal('amount_cents')
            : 0;
        $vatAmountCents = $this->expenseVatAmountCents($transaction);

        $meta = $transaction->expense_meta ?? [];
        $categoryName = strtolower((string) ($expenseLine?->account?->name ?? ''));
        if (str_contains($categoryName, 'home office')) {
            if (isset($meta['entered_amount_excl_vat_cents'])) {
                $amountExclCents = (int) $meta['entered_amount_excl_vat_cents'];
                $vatAmountCents = (int) ($meta['entered_vat_amount_cents'] ?? 0);
            } else {
                $officePct = (float) ($meta['office_percentage'] ?? 0);
                if ($officePct > 0) {
                    $factor = $officePct / 100.0;
                    $amountExclCents = (int) round($amountExclCents / $factor);
                    $vatAmountCents = (int) round($vatAmountCents / $factor);
                }
            }
        }

        $vatRate = 'no_vat';
        if ($vatAmountCents > 0) {
            $vatRate = 'vat15';
        } elseif ($transaction->taxLines->isNotEmpty()) {
            $vatRate = 'vat0';
        }

        $paidFromBankingAccountId = $this->resolvePaidFromBankingAccountIdForForm($transaction, $creditLine, $team);

        $attachments = $transaction->getMedia('attachments')->map(fn (Media $media) => [
            'id' => $media->id,
            'name' => $media->file_name,
            'mime_type' => (string) ($media->mime_type ?? ''),
            'size' => (int) $media->size,
            'url' => route('expenses.attachments.show', [$transaction, $media]),
        ])->values()->all();

        return [
            'id' => $transaction->id,
            'date' => optional($transaction->transaction_date)->toDateString(),
            'supplier_id' => $transaction->supplier_id ?? 0,
            'supplier_custom' => $transaction->supplier_id ? '' : (string) ($transaction->reference ?? ''),
            'category_account_id' => $expenseLine?->account_id ?? 0,
            'description' => (string) ($transaction->description ?? ''),
            'amount_excl_vat' => $amountExclCents / 100,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmountCents / 100,
            'paid_from_banking_account_id' => $paidFromBankingAccountId,
            'reference' => (string) ($meta['external_reference'] ?? ''),
            'notes' => (string) ($meta['notes'] ?? ''),
            'office_percentage' => (float) ($meta['office_percentage'] ?? 15),
            'distance_km' => (float) ($meta['distance_km'] ?? 0),
            'rate_per_km' => (float) ($meta['rate_per_km'] ?? 4.84),
            'attachments' => $attachments,
            'can_delete' => DeleteTransactionAction::canDelete($transaction)
                && $user !== null
                && $user->canOnTeam('expenses.delete', $team),
        ];
    }

    /**
     * Prefer stored banking meta, then banking linked to credit GL / legacy payment_method codes.
     */
    private function resolvePaidFromBankingAccountIdForForm(
        Transaction $transaction,
        ?JournalEntry $creditLine,
        Team $team,
    ): int {
        $meta = $transaction->expense_meta ?? [];
        $fromMeta = (int) ($meta['paid_from_banking_account_id'] ?? 0);
        if ($fromMeta > 0) {
            $existing = BankingAccount::queryWithoutTeamScope()
                ->where('team_id', $team->id)
                ->whereKey($fromMeta)
                ->whereNotNull('gl_account_id')
                ->first();
            if ($existing !== null) {
                return (int) $existing->id;
            }
        }

        $glAccountId = (int) ($meta['paid_from_account_id'] ?? 0);
        if ($glAccountId <= 0 && $creditLine?->account_id) {
            $glAccountId = (int) $creditLine->account_id;
        }

        if ($glAccountId <= 0) {
            $legacyMethod = (string) ($meta['payment_method'] ?? 'business_account');
            $code = match ($legacyMethod) {
                'personal_reimbursable', 'credit_card' => '2000',
                default => '1010',
            };
            $gl = Account::queryWithoutTeamScope()
                ->where('team_id', $team->id)
                ->where('code', $code)
                ->first();
            $glAccountId = $gl !== null ? (int) $gl->id : 0;
        }

        if ($glAccountId > 0) {
            $linked = BankingAccount::queryWithoutTeamScope()
                ->where('team_id', $team->id)
                ->where('gl_account_id', $glAccountId)
                ->first();
            if ($linked !== null) {
                return (int) $linked->id;
            }

            $gl = Account::queryWithoutTeamScope()
                ->where('team_id', $team->id)
                ->whereKey($glAccountId)
                ->first();

            if ($gl !== null && in_array($gl->type, [AccountType::Asset, AccountType::Liability], true)) {
                $created = BankingAccount::queryWithoutTeamScope()->create([
                    'team_id' => $team->id,
                    'name' => $gl->name,
                    'bank_name' => null,
                    'account_number_last4' => null,
                    'currency' => 'ZAR',
                    'type' => $gl->code === '2000' ? 'payable' : ($gl->code === '1020' ? 'cash' : 'cheque'),
                    'is_active' => true,
                    'gl_account_id' => $gl->id,
                ]);

                return (int) $created->id;
            }
        }

        return (int) (new EnsureDefaultBankingAccount)->execute($team)->id;
    }

    private function resolveTeamExpense(Request $request, Transaction $transaction): Transaction
    {
        abort_unless($transaction->team_id === (int) $request->user()->current_team_id, 403);
        abort_unless($transaction->type === TransactionType::Expense, 404);

        return $transaction;
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'categories' => array_values(array_filter(explode(',', (string) $request->string('categories')->toString()))),
            'supplier' => $request->string('supplier')->toString() ?: null,
            'has_receipt' => $request->string('has_receipt')->toString() ?: 'all',
            'vat_status' => $request->string('vat_status')->toString() ?: 'all',
        ];
    }

    /**
     * Keep list filters when leaving for an expense (receipt preview / edit) and returning via breadcrumbs.
     *
     * @return array{from: ?string, to: ?string, categories: list<string>, supplier: ?string, has_receipt: string, vat_status: string, page: int}
     */
    private function rememberedIndexFilters(Request $request, int $teamId): array
    {
        $sessionKey = $this->indexFiltersSessionKey($teamId);
        $queryKeys = ['from', 'to', 'categories', 'supplier', 'has_receipt', 'vat_status', 'page'];

        if ($request->hasAny($queryKeys)) {
            $filters = $this->normalizeIndexFilters(
                $this->filters($request),
                $request->has('page') ? (int) $request->integer('page') : 1,
            );
            $request->session()->put($sessionKey, $filters);

            return $filters;
        }

        $stored = $request->session()->get($sessionKey);
        if (! is_array($stored)) {
            return $this->normalizeIndexFilters();
        }

        return $this->normalizeIndexFilters($stored, (int) ($stored['page'] ?? 1));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{from: ?string, to: ?string, categories: list<string>, supplier: ?string, has_receipt: string, vat_status: string, page: int}
     */
    private function normalizeIndexFilters(array $filters = [], int $page = 1): array
    {
        $hasReceipt = $filters['has_receipt'] ?? 'all';
        if (! in_array($hasReceipt, ['yes', 'no', 'all'], true)) {
            $hasReceipt = 'all';
        }

        $vatStatus = $filters['vat_status'] ?? 'all';
        if (! in_array($vatStatus, ['claimable', 'non_claimable', 'all'], true)) {
            $vatStatus = 'all';
        }

        $categories = $filters['categories'] ?? [];
        if (! is_array($categories)) {
            $categories = array_values(array_filter(explode(',', (string) $categories)));
        }
        $categories = array_values(array_filter(array_map('strval', $categories), fn (string $name): bool => $name !== ''));

        $from = isset($filters['from']) ? trim((string) $filters['from']) : '';
        $to = isset($filters['to']) ? trim((string) $filters['to']) : '';
        $supplier = isset($filters['supplier']) ? trim((string) $filters['supplier']) : '';

        return [
            'from' => $from !== '' ? $from : null,
            'to' => $to !== '' ? $to : null,
            'categories' => $categories,
            'supplier' => $supplier !== '' ? $supplier : null,
            'has_receipt' => $hasReceipt,
            'vat_status' => $vatStatus,
            'page' => max(1, $page),
        ];
    }

    private function indexFiltersSessionKey(int $teamId): string
    {
        return 'expenses.index_filters.'.$teamId;
    }
}
