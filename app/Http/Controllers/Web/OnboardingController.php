<?php

namespace App\Http\Controllers\Web;

use App\Domain\Accounting\Actions\PostTransactionAction;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Accounting\Services\LedgerService;
use App\Domain\Invoicing\Models\InvoiceNumberSequence;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\TeamBankAccount;
use App\Models\User;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    /**
     * @return list<array{value: string, label: string}>
     */
    private function industryOptions(): array
    {
        return [
            ['value' => 'professional_services', 'label' => 'Professional services'],
            ['value' => 'technology', 'label' => 'Technology / IT'],
            ['value' => 'construction', 'label' => 'Construction'],
            ['value' => 'retail', 'label' => 'Retail'],
            ['value' => 'hospitality', 'label' => 'Hospitality'],
            ['value' => 'agriculture', 'label' => 'Agriculture'],
            ['value' => 'manufacturing', 'label' => 'Manufacturing'],
            ['value' => 'healthcare', 'label' => 'Healthcare'],
            ['value' => 'other', 'label' => 'Other'],
        ];
    }

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if ($user->completed_onboarding_at !== null) {
            return redirect()->route('dashboard');
        }

        $team = $user->currentTeam;
        abort_unless($team !== null && $user->can('update', $team), 403);

        $settings = $team->mergedCompanySettings();
        $year = (int) now()->format('Y');
        $sequenceRow = InvoiceNumberSequence::query()
            ->where('team_id', $team->id)
            ->where('year', $year)
            ->first();

        return Inertia::render('Onboarding/Setup', [
            'industries' => $this->industryOptions(),
            'financial_year_months' => collect(range(1, 12))->map(fn (int $m): array => [
                'value' => $m,
                'label' => now()->month($m)->format('F'),
            ])->all(),
            'bank_account_types' => [
                ['value' => 'current', 'label' => 'Current'],
                ['value' => 'savings', 'label' => 'Savings'],
            ],
            'initial' => [
                'team_name' => $team->name,
                'vat_registered' => (bool) ($settings['vat_registered'] ?? true),
                'vat_number' => (string) ($settings['vat_number'] ?? ''),
                'financial_year_end_month' => (int) ($settings['financial_year_end_month'] ?? 2),
                'industry' => (string) ($settings['industry'] ?? ''),
                'invoice_default_payment_terms_days' => (int) ($settings['invoice_default_payment_terms_days'] ?? 30),
                'invoice_prefix' => (string) ($settings['invoice_prefix'] ?? 'INV'),
                'invoice_next_sequence' => $sequenceRow?->next_number ?? 1,
                'bank_name' => (string) ($settings['bank_name'] ?? ''),
                'bank_account_holder' => (string) ($settings['bank_account_holder'] ?? ''),
                'bank_account_number' => (string) ($settings['bank_account_number'] ?? ''),
                'bank_branch_code' => (string) ($settings['bank_branch_code'] ?? ''),
                'bank_account_type' => (string) ($settings['bank_account_type'] ?? 'current'),
            ],
            'session_wizard' => $request->session()->get('onboarding_wizard'),
            'session_step' => $request->session()->get('onboarding_step', 1),
        ]);
    }

    public function saveProgress(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->completed_onboarding_at !== null, 403);

        $request->validate([
            'step' => ['required', 'integer', 'min:1', 'max:5'],
            'wizard' => ['required', 'array'],
        ]);

        $request->session()->put('onboarding_wizard', $request->input('wizard'));
        $request->session()->put('onboarding_step', (int) $request->input('step'));

        return back();
    }

    public function skip(Request $request): RedirectResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;
        abort_unless($team !== null && $user->can('update', $team), 403);

        $this->ensureChartForTeam($team);

        $user->forceFill(['completed_onboarding_at' => now()])->save();
        $request->session()->forget(['onboarding_wizard', 'onboarding_step']);

        return redirect()->route('dashboard');
    }

    public function complete(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->completed_onboarding_at !== null) {
            return redirect()->route('dashboard');
        }

        $team = $user->currentTeam;
        abort_unless($team !== null && $user->can('update', $team), 403);

        $industryValues = array_column($this->industryOptions(), 'value');

        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'vat_registered' => ['required', 'boolean'],
            'vat_number' => [
                'nullable',
                Rule::requiredIf(fn () => $request->boolean('vat_registered')),
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    if (! preg_match('/^4\d{9}$/', (string) $value)) {
                        $fail('The VAT number must be a valid South African VAT number (10 digits starting with 4).');
                    }
                },
            ],
            'financial_year_end_month' => ['required', 'integer', Rule::in(range(1, 12))],
            'industry' => ['nullable', 'string', 'max:64', Rule::in($industryValues)],
            'has_existing_books' => ['required', 'boolean'],
            'opening_bank' => ['nullable', 'numeric', 'min:0'],
            'opening_ar' => ['nullable', 'numeric', 'min:0'],
            'opening_ap' => ['nullable', 'numeric', 'min:0'],
            'invoice_default_payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'invoice_prefix' => ['required', 'string', 'max:32'],
            'invoice_next_sequence' => ['required', 'integer', 'min:1', 'max:999999'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_holder' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:64'],
            'bank_branch_code' => ['nullable', 'string', 'max:32'],
            'bank_account_type' => ['required', Rule::in(['current', 'savings'])],
            'logo' => ['nullable', 'image', 'max:4096'],
        ]);

        if (! $validated['vat_registered']) {
            $validated['vat_number'] = null;
        }

        $this->ensureChartForTeam($team);

        $footerLines = array_values(array_filter([
            $validated['bank_name'] ?: null,
            $validated['bank_account_holder'] ?: null,
            filled($validated['bank_account_number']) ? 'Acc: '.$validated['bank_account_number'] : null,
            filled($validated['bank_branch_code']) ? 'Branch: '.$validated['bank_branch_code'] : null,
        ], fn (?string $v): bool => filled($v)));

        $invoiceFooter = $footerLines !== [] ? implode("\n", $footerLines) : null;

        $settingsKeys = [
            'industry' => $validated['industry'],
            'financial_year_end_month' => (int) $validated['financial_year_end_month'],
            'invoice_default_payment_terms_days' => (int) $validated['invoice_default_payment_terms_days'],
            'invoice_prefix' => $validated['invoice_prefix'],
            'vat_registered' => $validated['vat_registered'],
            'vat_number' => $validated['vat_number'],
            'bank_name' => $validated['bank_name'],
            'bank_account_holder' => $validated['bank_account_holder'],
            'bank_account_number' => $validated['bank_account_number'],
            'bank_branch_code' => $validated['bank_branch_code'],
            'bank_account_type' => $validated['bank_account_type'],
            'invoice_default_footer' => $invoiceFooter,
        ];

        $newSettings = [];
        foreach ($settingsKeys as $key => $value) {
            $newSettings[$key] = $value;
        }

        DB::transaction(function () use ($user, $team, $validated, $newSettings, $request): void {
            $team->name = $validated['company_name'];
            $team->company_settings = array_replace_recursive(
                $team->mergedCompanySettings(),
                $newSettings
            );
            $team->save();

            if ($request->hasFile('logo')) {
                $team->clearMediaCollection('logo');
                $team->addMediaFromRequest('logo')->toMediaCollection('logo');
            }

            $hasBankRow = filled($validated['bank_name'] ?? null)
                || filled($validated['bank_account_holder'] ?? null)
                || filled($validated['bank_account_number'] ?? null)
                || filled($validated['bank_branch_code'] ?? null);
            if ($hasBankRow) {
                TeamBankAccount::query()->create([
                    'team_id' => (int) $team->id,
                    'sort_order' => 0,
                    'title' => null,
                    'bank_name' => $validated['bank_name'] ?: null,
                    'bank_account_holder' => $validated['bank_account_holder'] ?: null,
                    'bank_account_number' => $validated['bank_account_number'] ?: null,
                    'bank_branch_code' => $validated['bank_branch_code'] ?: null,
                    'bank_account_type' => $validated['bank_account_type'],
                    'show_on_invoice' => true,
                ]);
            }

            InvoiceNumberSequence::query()->updateOrCreate(
                [
                    'team_id' => (int) $team->id,
                    'year' => (int) now()->format('Y'),
                ],
                [
                    'next_number' => (int) $validated['invoice_next_sequence'],
                ]
            );

            if ($validated['has_existing_books']) {
                $this->postOpeningBalances(
                    $team,
                    $user,
                    $this->zarToCents($validated['opening_bank'] ?? null),
                    $this->zarToCents($validated['opening_ar'] ?? null),
                    $this->zarToCents($validated['opening_ap'] ?? null),
                );
            }

            $user->forceFill(['completed_onboarding_at' => now()])->save();
        });

        $request->session()->forget(['onboarding_wizard', 'onboarding_step']);

        return redirect()->route('dashboard')->with('success', 'You are ready to use '.config('app.name').'.');
    }

    private function zarToCents(?string $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return (int) round((float) $value * 100);
    }

    private function ensureChartForTeam(Team $team): void
    {
        $exists = Account::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->exists();

        if (! $exists) {
            (new DefaultChartOfAccountsSeeder)->runForTeam($team);
        }
    }

    private function postOpeningBalances(Team $team, User $user, int $bankCents, int $arCents, int $apCents): void
    {
        if ($bankCents === 0 && $arCents === 0 && $apCents === 0) {
            return;
        }

        $accounts = Account::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->whereIn('code', ['1010', '1100', '2000', '3000'])
            ->get()
            ->keyBy('code');

        foreach (['1010', '1100', '2000', '3000'] as $code) {
            if (! $accounts->has($code)) {
                throw new \RuntimeException('Chart of accounts is missing required account '.$code.'.');
            }
        }

        $plug = $bankCents + $arCents - $apCents;

        $transaction = Transaction::query()->create([
            'team_id' => $team->id,
            'type' => TransactionType::OpeningBalance,
            'status' => TransactionStatus::Draft,
            'reference' => 'OB-SETUP',
            'description' => 'Opening balances (setup wizard)',
            'transaction_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);

        if ($bankCents > 0) {
            JournalEntry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $accounts['1010']->id,
                'type' => EntryType::Debit,
                'amount_cents' => $bankCents,
                'currency' => 'ZAR',
            ]);
        }

        if ($arCents > 0) {
            JournalEntry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $accounts['1100']->id,
                'type' => EntryType::Debit,
                'amount_cents' => $arCents,
                'currency' => 'ZAR',
            ]);
        }

        if ($apCents > 0) {
            JournalEntry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $accounts['2000']->id,
                'type' => EntryType::Credit,
                'amount_cents' => $apCents,
                'currency' => 'ZAR',
            ]);
        }

        if ($plug >= 0) {
            if ($plug > 0) {
                JournalEntry::query()->create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $accounts['3000']->id,
                    'type' => EntryType::Credit,
                    'amount_cents' => $plug,
                    'currency' => 'ZAR',
                ]);
            }
        } elseif ($plug < 0) {
            JournalEntry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $accounts['3000']->id,
                'type' => EntryType::Debit,
                'amount_cents' => abs($plug),
                'currency' => 'ZAR',
            ]);
        }

        (new PostTransactionAction(new LedgerService))->execute($transaction->fresh());
    }
}
