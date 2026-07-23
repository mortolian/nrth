<?php

namespace Tests\Feature\Expenses;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Tax\Models\TaxRate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ExpenseCrudTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    /**
     * @return array{0: User, 1: Team, 2: Account, 3: Account, 4: BankingAccount}
     */
    private function teamWithExpenseAccounts(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        Account::factory()->for($team)->expense()->create(['code' => '7500', 'name' => 'General expense']);
        $bankGl = Account::factory()->for($team)->asset()->create(['code' => '1010', 'name' => 'Bank', 'is_system' => true]);
        Account::factory()->for($team)->asset()->create(['code' => '1020', 'name' => 'Cash on hand', 'is_system' => true]);
        Account::factory()->for($team)->liability()->create(['code' => '2000', 'name' => 'Accounts Payable', 'is_system' => true]);

        $category = Account::queryWithoutTeamScope()->where('team_id', $team->id)->where('code', '7500')->first();
        $this->assertNotNull($category);

        $banking = BankingAccount::factory()->for($team)->create([
            'name' => 'Operating bank',
            'gl_account_id' => $bankGl->id,
        ]);

        return [$user, $team, $category, $bankGl, $banking];
    }

    public function test_store_posts_credit_to_banking_linked_gl(): void
    {
        [, $team, $category, $bankGl, $banking] = $this->teamWithExpenseAccounts();

        $this->post(route('expenses.store'), [
            'date' => '2026-05-01',
            'supplier' => 'Corner Cafe',
            'category_account_id' => $category->id,
            'description' => 'Coffee',
            'amount_excl_vat_cents' => 4500,
            'vat_rate' => 'no_vat',
            'vat_amount_cents' => 0,
            'paid_from_banking_account_id' => $banking->id,
            'reference' => null,
            'notes' => null,
        ])->assertRedirect(route('expenses.index'));

        $txn = Transaction::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('type', TransactionType::Expense)
            ->latest('id')
            ->first();
        $this->assertNotNull($txn);
        $this->assertSame($banking->id, (int) ($txn->expense_meta['paid_from_banking_account_id'] ?? 0));
        $this->assertSame($bankGl->id, (int) ($txn->expense_meta['paid_from_account_id'] ?? 0));
        $this->assertSame('1010 - Bank', $txn->expense_meta['paid_from_account_name'] ?? null);

        $credit = $txn->journalEntries->first(fn ($entry) => $entry->type === EntryType::Credit);
        $this->assertNotNull($credit);
        $this->assertSame($bankGl->id, (int) $credit->account_id);
    }

    public function test_create_form_ensures_default_banking_account_for_bank_gl(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        Account::factory()->for($team)->expense()->create(['code' => '7500', 'name' => 'General expense']);

        $this->assertNull(
            Account::queryWithoutTeamScope()->where('team_id', $team->id)->where('code', '1010')->first()
        );

        $this->get(route('expenses.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Expenses/Form')
                ->has('paid_from_options.0.id')
                ->where('paid_from_options.0.gl_label', fn ($label) => str_starts_with((string) $label, '1010')));

        $bankGl = Account::queryWithoutTeamScope()->where('team_id', $team->id)->where('code', '1010')->first();
        $this->assertNotNull($bankGl);
        $this->assertNotNull(
            BankingAccount::queryWithoutTeamScope()
                ->where('team_id', $team->id)
                ->where('gl_account_id', $bankGl->id)
                ->first()
        );
    }

    public function test_store_rejects_inactive_unlinked_and_other_team_banking(): void
    {
        [, $team, $category, $bankGl] = $this->teamWithExpenseAccounts();

        $inactive = BankingAccount::factory()->for($team)->create([
            'name' => 'Old',
            'is_active' => false,
            'gl_account_id' => Account::factory()->for($team)->asset()->create(['code' => '1098', 'name' => 'Spare'])->id,
        ]);
        $unlinked = BankingAccount::factory()->for($team)->create([
            'name' => 'Import only',
            'gl_account_id' => null,
        ]);

        $otherUser = User::factory()->withPersonalTeam()->create();
        $otherTeam = $otherUser->currentTeam;
        $this->assertNotNull($otherTeam);
        $otherGl = Account::factory()->for($otherTeam)->asset()->create(['code' => '1010', 'name' => 'Other Bank']);
        $otherBanking = BankingAccount::factory()->for($otherTeam)->create([
            'name' => 'Other',
            'gl_account_id' => $otherGl->id,
        ]);

        $base = [
            'date' => '2026-05-01',
            'supplier' => 'Reject Co',
            'category_account_id' => $category->id,
            'description' => 'Nope',
            'amount_excl_vat_cents' => 10_00,
            'vat_rate' => 'no_vat',
            'vat_amount_cents' => 0,
        ];

        $this->post(route('expenses.store'), [...$base, 'paid_from_banking_account_id' => $inactive->id])
            ->assertSessionHasErrors('paid_from_banking_account_id');

        $this->post(route('expenses.store'), [...$base, 'paid_from_banking_account_id' => $unlinked->id])
            ->assertSessionHasErrors('paid_from_banking_account_id');

        $this->post(route('expenses.store'), [...$base, 'paid_from_banking_account_id' => $otherBanking->id])
            ->assertSessionHasErrors('paid_from_banking_account_id');

        unset($bankGl);
    }

    public function test_edit_legacy_payment_method_maps_to_banking_account(): void
    {
        [, $team, $category] = $this->teamWithExpenseAccounts();
        $ap = Account::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('code', '2000')
            ->first();
        $this->assertNotNull($ap);

        $txn = Transaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Posted,
            'reference' => 'Legacy Vendor',
            'description' => 'Old expense',
            'expense_meta' => [
                'payment_method' => 'personal_reimbursable',
                'external_reference' => '',
                'notes' => '',
            ],
            'transaction_date' => '2026-04-01',
            'created_by' => User::query()->where('current_team_id', $team->id)->value('id'),
        ]);

        $txn->journalEntries()->create([
            'account_id' => $category->id,
            'type' => EntryType::Debit,
            'amount_cents' => 50_00,
            'currency' => 'ZAR',
            'description' => 'Expense',
        ]);
        $txn->journalEntries()->create([
            'account_id' => $ap->id,
            'type' => EntryType::Credit,
            'amount_cents' => 50_00,
            'currency' => 'ZAR',
            'description' => 'Expense payment',
        ]);

        $apBanking = BankingAccount::factory()->for($team)->create([
            'name' => 'Reimbursable',
            'gl_account_id' => $ap->id,
        ]);

        $this->get(route('expenses.edit', $txn))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Expenses/Form')
                ->where('expense.paid_from_banking_account_id', $apBanking->id));
    }

    public function test_store_update_delete_and_receipt(): void
    {
        [, $team, $category, , $banking] = $this->teamWithExpenseAccounts();

        $this->post(route('expenses.store'), [
            'date' => '2026-05-01',
            'supplier' => 'Stationery Co',
            'category_account_id' => $category->id,
            'description' => 'Paper',
            'amount_excl_vat_cents' => 100_00,
            'vat_rate' => 'no_vat',
            'vat_amount_cents' => 0,
            'paid_from_banking_account_id' => $banking->id,
            'reference' => 'PO-99',
            'notes' => 'Quarterly',
        ])->assertRedirect(route('expenses.index'));

        $txn = Transaction::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('type', TransactionType::Expense)
            ->latest('id')
            ->first();
        $this->assertNotNull($txn);
        $this->assertSame(TransactionStatus::Posted, $txn->status);
        $this->assertSame('PO-99', $txn->expense_meta['external_reference'] ?? null);
        $this->assertSame('Quarterly', $txn->expense_meta['notes'] ?? null);

        $this->put(route('expenses.update', $txn), [
            'date' => '2026-05-02',
            'supplier' => 'Stationery Co',
            'category_account_id' => $category->id,
            'description' => 'Paper and pens',
            'amount_excl_vat_cents' => 200_00,
            'vat_rate' => 'no_vat',
            'vat_amount_cents' => 0,
            'paid_from_banking_account_id' => $banking->id,
            'reference' => 'PO-100',
            'notes' => 'Updated',
        ])->assertRedirect(route('expenses.index'));

        $txn->refresh();
        $this->assertSame('2026-05-02', $txn->transaction_date->toDateString());
        $this->assertSame('Paper and pens', $txn->description);
        $this->assertSame('PO-100', $txn->expense_meta['external_reference'] ?? null);
        $this->assertSame('Updated', $txn->expense_meta['notes'] ?? null);
        $expenseLine = $txn->journalEntries->first(fn ($e) => $e->account?->type === AccountType::Expense);
        $this->assertNotNull($expenseLine);
        $this->assertSame(200_00, (int) $expenseLine->getRawOriginal('amount_cents'));

        $this->post(route('expenses.receipt.store', $txn), [
            'receipt' => UploadedFile::fake()->create('rcpt.pdf', 120),
        ])->assertRedirect()
            ->assertSessionHas('success', 'Receipt attached successfully.');

        $this->assertGreaterThanOrEqual(1, $txn->fresh()->getMedia('attachments')->count());

        $media = $txn->fresh()->getMedia('attachments')->first();
        $this->assertNotNull($media);

        $this->get(route('expenses.edit', $txn->fresh()))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Expenses/Form')
                ->has('expense.attachments', 1)
                ->where('expense.attachments.0.id', $media->id)
                ->where('expense.attachments.0.name', $media->file_name));

        $this->get(route('expenses.attachments.show', [$txn->fresh(), $media]))
            ->assertOk()
            ->assertHeader('content-type', $media->mime_type ?: 'application/pdf');

        $this->put(route('expenses.update', $txn->fresh()), [
            'date' => '2026-05-02',
            'supplier' => 'Stationery Co',
            'category_account_id' => $category->id,
            'description' => 'Paper and pens',
            'amount_excl_vat_cents' => 200_00,
            'vat_rate' => 'no_vat',
            'vat_amount_cents' => 0,
            'paid_from_banking_account_id' => $banking->id,
            'reference' => 'PO-100',
            'notes' => 'Updated',
            'remove_attachment_ids' => [$media->id],
        ])->assertRedirect(route('expenses.index'));

        $this->assertCount(0, $txn->fresh()->getMedia('attachments'));

        $this->post(route('expenses.receipt.store', $txn->fresh()), [
            'receipt' => UploadedFile::fake()->create('rcpt2.pdf', 80),
        ])->assertRedirect()
            ->assertSessionHas('success');

        $media2 = $txn->fresh()->getMedia('attachments')->first();
        $this->assertNotNull($media2);

        $this->delete(route('expenses.attachments.destroy', [$txn->fresh(), $media2]))
            ->assertRedirect();

        $this->assertCount(0, $txn->fresh()->getMedia('attachments'));

        $this->delete(route('expenses.destroy', $txn->fresh()))->assertRedirect(route('expenses.index'));
        $this->assertNull(Transaction::queryWithoutTeamScope()->find($txn->id));
    }

    public function test_store_accepts_multiple_receipt_files(): void
    {
        [, $team, $category, , $banking] = $this->teamWithExpenseAccounts();

        $this->post(route('expenses.store'), [
            'date' => '2026-05-01',
            'supplier' => 'Multi Receipt Co',
            'category_account_id' => $category->id,
            'description' => 'Office supplies',
            'amount_excl_vat_cents' => 50_00,
            'vat_rate' => 'no_vat',
            'vat_amount_cents' => 0,
            'paid_from_banking_account_id' => $banking->id,
            'receipts' => [
                UploadedFile::fake()->image('page1.jpg'),
                UploadedFile::fake()->create('page2.pdf', 80, 'application/pdf'),
            ],
        ])->assertRedirect(route('expenses.index'));

        $txn = Transaction::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('type', TransactionType::Expense)
            ->latest('id')
            ->first();
        $this->assertNotNull($txn);
        $this->assertCount(2, $txn->getMedia('attachments'));
    }

    public function test_travel_category_uses_distance_times_rate(): void
    {
        [, $team, , , $banking] = $this->teamWithExpenseAccounts();

        $travel = Account::factory()->for($team)->expense()->create(['code' => '7600', 'name' => 'Travel — mileage']);

        $this->post(route('expenses.store'), [
            'date' => '2026-05-01',
            'supplier' => 'SARS rate',
            'category_account_id' => $travel->id,
            'description' => 'Client visit',
            'amount_excl_vat_cents' => 999_99,
            'vat_rate' => 'vat15',
            'vat_amount_cents' => 0,
            'paid_from_banking_account_id' => $banking->id,
            'distance_km' => 10,
            'rate_per_km' => 3.50,
        ])->assertRedirect(route('expenses.index'));

        $txn = Transaction::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('type', TransactionType::Expense)
            ->latest('id')
            ->first();
        $this->assertNotNull($txn);
        $line = $txn->journalEntries->first(fn ($e) => $e->account?->type === AccountType::Expense);
        $this->assertSame(35_00, (int) $line->getRawOriginal('amount_cents'));
    }

    public function test_home_office_scales_amounts(): void
    {
        [, $team, , , $banking] = $this->teamWithExpenseAccounts();
        TaxRate::factory()->for($team)->create();

        $home = Account::factory()->for($team)->expense()->create(['code' => '7700', 'name' => 'Home office']);

        $this->post(route('expenses.store'), [
            'date' => '2026-05-01',
            'supplier' => 'Telkom',
            'category_account_id' => $home->id,
            'description' => 'Internet',
            'amount_excl_vat_cents' => 1000_00,
            'vat_rate' => 'vat15',
            'vat_amount_cents' => 150_00,
            'paid_from_banking_account_id' => $banking->id,
            'office_percentage' => 25,
        ])->assertRedirect(route('expenses.index'));

        $txn = Transaction::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('type', TransactionType::Expense)
            ->latest('id')
            ->first();
        $this->assertNotNull($txn);
        $line = $txn->journalEntries->first(fn ($e) => $e->account?->type === AccountType::Expense);
        $this->assertSame(250_00, (int) $line->getRawOriginal('amount_cents'));
        $vatLine = $txn->taxLines->first();
        $this->assertNotNull($vatLine);
        $this->assertSame(37_50, (int) $vatLine->tax_amount_cents);
    }

    public function test_create_page_includes_prefill_from_query(): void
    {
        [, $team] = $this->teamWithExpenseAccounts();

        $supplier = Supplier::factory()->for($team)->create(['name' => 'Prefill Vendor']);

        $this->get(route('expenses.create', ['supplier_id' => $supplier->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Expenses/Form')
                ->where('prefill.supplier_id', $supplier->id)
                ->has('paid_from_options'));
    }

    public function test_export_csv_downloads_selected_expenses(): void
    {
        [, $team, $category, , $banking] = $this->teamWithExpenseAccounts();

        $this->post(route('expenses.store'), [
            'date' => '2026-05-01',
            'supplier' => 'Csv Cafe',
            'category_account_id' => $category->id,
            'description' => 'Lunch',
            'amount_excl_vat_cents' => 80_00,
            'vat_rate' => 'no_vat',
            'vat_amount_cents' => 0,
            'paid_from_banking_account_id' => $banking->id,
            'reference' => 'REF-1',
        ])->assertRedirect(route('expenses.index'));

        $txn = Transaction::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('type', TransactionType::Expense)
            ->latest('id')
            ->first();
        $this->assertNotNull($txn);

        $response = $this->get(route('expenses.export', ['ids' => [$txn->id]]));
        $response->assertOk();
        $response->assertHeader('content-disposition');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Date,Supplier,Category,Description', $csv);
        $this->assertStringContainsString('Total (ZAR)', $csv);
        $this->assertStringContainsString('Csv Cafe', $csv);
        $this->assertStringContainsString('80.00', $csv);
        $this->assertStringContainsString('REF-1', $csv);
    }


    public function test_update_via_multipart_method_spoof_persists_fields(): void
    {
        [, $team, $category, , $banking] = $this->teamWithExpenseAccounts();

        $this->post(route('expenses.store'), [
            'date' => '2026-05-01',
            'supplier' => 'Original Co',
            'category_account_id' => $category->id,
            'description' => 'Original desc',
            'amount_excl_vat_cents' => 100_00,
            'vat_rate' => 'no_vat',
            'vat_amount_cents' => 0,
            'paid_from_banking_account_id' => $banking->id,
            'reference' => 'REF-1',
            'notes' => 'Note 1',
        ])->assertRedirect(route('expenses.index'));

        $txn = Transaction::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('type', TransactionType::Expense)
            ->latest('id')
            ->first();
        $this->assertNotNull($txn);

        $this->call('POST', route('expenses.update', $txn), [
            '_method' => 'PUT',
            'date' => '2026-06-15',
            'supplier' => 'Updated Co',
            'category_account_id' => (string) $category->id,
            'description' => 'Updated desc',
            'amount_excl_vat_cents' => '25000',
            'vat_rate' => 'no_vat',
            'vat_amount_cents' => '0',
            'paid_from_banking_account_id' => (string) $banking->id,
            'reference' => 'REF-2',
            'notes' => 'Note 2',
        ])->assertRedirect(route('expenses.index'));

        $txn->refresh();
        $this->assertSame('2026-06-15', $txn->transaction_date->toDateString());
        $this->assertSame('Updated desc', $txn->description);
        $this->assertSame('Updated Co', $txn->reference);
        $this->assertSame('REF-2', $txn->expense_meta['external_reference'] ?? null);
        $this->assertSame('Note 2', $txn->expense_meta['notes'] ?? null);
        $expenseLine = $txn->journalEntries->first(fn ($e) => $e->account?->type === AccountType::Expense);
        $this->assertSame(250_00, (int) $expenseLine->getRawOriginal('amount_cents'));
    }

    public function test_home_office_update_does_not_reapply_percentage_to_scaled_amount(): void
    {
        [, $team, , , $banking] = $this->teamWithExpenseAccounts();
        TaxRate::factory()->for($team)->create();
        $home = Account::factory()->for($team)->expense()->create(['code' => '7700', 'name' => 'Home office']);

        $this->post(route('expenses.store'), [
            'date' => '2026-05-01',
            'supplier' => 'Telkom',
            'category_account_id' => $home->id,
            'description' => 'Internet',
            'amount_excl_vat_cents' => 1000_00,
            'vat_rate' => 'vat15',
            'vat_amount_cents' => 150_00,
            'paid_from_banking_account_id' => $banking->id,
            'office_percentage' => 25,
        ])->assertRedirect(route('expenses.index'));

        $txn = Transaction::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('type', TransactionType::Expense)
            ->latest('id')
            ->first();
        $this->assertNotNull($txn);

        $edit = $this->get(route('expenses.edit', $txn))->assertOk();
        $edit->assertInertia(fn ($page) => $page
            ->component('Expenses/Form')
            ->where('expense.amount_excl_vat', 1000)
            ->where('expense.vat_amount', 150)
            ->where('expense.description', 'Internet'));

        $this->put(route('expenses.update', $txn), [
            'date' => '2026-05-01',
            'supplier' => 'Telkom',
            'category_account_id' => $home->id,
            'description' => 'Internet updated',
            'amount_excl_vat_cents' => 1000_00,
            'vat_rate' => 'vat15',
            'vat_amount_cents' => 150_00,
            'paid_from_banking_account_id' => $banking->id,
            'office_percentage' => 25,
        ])->assertRedirect(route('expenses.index'));

        $txn->refresh();
        $this->assertSame('Internet updated', $txn->description);
        $this->assertSame(1000_00, (int) ($txn->expense_meta['entered_amount_excl_vat_cents'] ?? 0));
        $line = $txn->journalEntries->first(fn ($e) => $e->account?->type === AccountType::Expense);
        $this->assertSame(250_00, (int) $line->getRawOriginal('amount_cents'));
    }

    public function test_store_and_update_persist_vat_without_preseeded_tax_rate(): void
    {
        [, $team, $category, , $banking] = $this->teamWithExpenseAccounts();

        $this->assertSame(
            0,
            TaxRate::queryWithoutTeamScope()->where('team_id', $team->id)->count()
        );

        $this->post(route('expenses.store'), [
            'date' => '2026-05-01',
            'supplier' => 'VAT Cafe',
            'category_account_id' => $category->id,
            'description' => 'Lunch',
            'amount_excl_vat_cents' => 100_00,
            'vat_rate' => 'vat15',
            'vat_amount_cents' => 15_00,
            'paid_from_banking_account_id' => $banking->id,
        ])->assertRedirect(route('expenses.index'));

        $txn = Transaction::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('type', TransactionType::Expense)
            ->latest('id')
            ->first();
        $this->assertNotNull($txn);
        $this->assertGreaterThan(0, TaxRate::queryWithoutTeamScope()->where('team_id', $team->id)->count());
        $this->assertSame(15_00, (int) $txn->taxLines->sum('tax_amount_cents'));
        $vatLine = $txn->journalEntries->first(
            fn ($e) => $e->type === EntryType::Debit && $e->account?->code === '1200'
        );
        $this->assertNotNull($vatLine);
        $this->assertSame(15_00, (int) $vatLine->getRawOriginal('amount_cents'));

        $this->get(route('expenses.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Expenses/Index')
                ->where('expenses.data.0.vat_amount', 15_00)
                ->where('expenses.data.0.total', 115_00));

        $this->put(route('expenses.update', $txn), [
            'date' => '2026-05-01',
            'supplier' => 'VAT Cafe',
            'category_account_id' => $category->id,
            'description' => 'Lunch updated',
            'amount_excl_vat_cents' => 200_00,
            'vat_rate' => 'vat15',
            'vat_amount_cents' => 30_00,
            'paid_from_banking_account_id' => $banking->id,
        ])->assertRedirect(route('expenses.index'));

        $txn->refresh();
        $this->assertSame('Lunch updated', $txn->description);
        $this->assertSame(30_00, (int) $txn->taxLines->sum('tax_amount_cents'));
        $vatLine = $txn->journalEntries->first(
            fn ($e) => $e->type === EntryType::Debit && $e->account?->code === '1200'
        );
        $this->assertNotNull($vatLine);
        $this->assertSame(30_00, (int) $vatLine->getRawOriginal('amount_cents'));

        $this->get(route('expenses.edit', $txn))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Expenses/Form')
                ->where('expense.vat_rate', 'vat15')
                ->where('expense.vat_amount', 30));
    }

    public function test_export_csv_rejects_empty_selection(): void
    {
        $this->teamWithExpenseAccounts();

        $this->get(route('expenses.export'))
            ->assertSessionHasErrors('ids');
    }
}
