<?php

namespace Tests\Feature\Expenses;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\Transaction;
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
     * @return array{0: User, 1: Team, 2: Account}
     */
    private function teamWithExpenseAccounts(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        Account::factory()->for($team)->expense()->create(['code' => '7500', 'name' => 'General expense']);
        Account::factory()->for($team)->asset()->create(['code' => '1010', 'name' => 'Bank', 'is_system' => true]);
        Account::factory()->for($team)->liability()->create(['code' => '2000', 'name' => 'Credit card', 'is_system' => true]);

        $category = Account::queryWithoutTeamScope()->where('team_id', $team->id)->where('code', '7500')->first();
        $this->assertNotNull($category);

        return [$user, $team, $category];
    }

    public function test_store_update_delete_and_receipt(): void
    {
        [, $team, $category] = $this->teamWithExpenseAccounts();

        $this->post(route('expenses.store'), [
            'date' => '2026-05-01',
            'supplier' => 'Stationery Co',
            'category_account_id' => $category->id,
            'description' => 'Paper',
            'amount_excl_vat_cents' => 100_00,
            'vat_rate' => 'no_vat',
            'vat_amount_cents' => 0,
            'payment_method' => 'business_account',
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
            'payment_method' => 'business_account',
            'reference' => 'PO-100',
            'notes' => 'Updated',
        ])->assertRedirect(route('expenses.index'));

        $txn->refresh();
        $this->assertSame('2026-05-02', $txn->transaction_date->toDateString());
        $expenseLine = $txn->journalEntries->first(fn ($e) => $e->account?->type === AccountType::Expense);
        $this->assertNotNull($expenseLine);
        $this->assertSame(200_00, (int) $expenseLine->getRawOriginal('amount_cents'));

        $this->post(route('expenses.receipt.store', $txn), [
            'receipt' => UploadedFile::fake()->create('rcpt.pdf', 120),
        ])->assertRedirect();

        $this->assertGreaterThanOrEqual(1, $txn->fresh()->getMedia('attachments')->count());

        $this->delete(route('expenses.destroy', $txn->fresh()))->assertRedirect(route('expenses.index'));
        $this->assertNull(Transaction::queryWithoutTeamScope()->find($txn->id));
    }

    public function test_travel_category_uses_distance_times_rate(): void
    {
        [, $team] = $this->teamWithExpenseAccounts();

        $travel = Account::factory()->for($team)->expense()->create(['code' => '7600', 'name' => 'Travel — mileage']);

        $this->post(route('expenses.store'), [
            'date' => '2026-05-01',
            'supplier' => 'SARS rate',
            'category_account_id' => $travel->id,
            'description' => 'Client visit',
            'amount_excl_vat_cents' => 999_99,
            'vat_rate' => 'vat15',
            'vat_amount_cents' => 0,
            'payment_method' => 'business_account',
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
        [, $team] = $this->teamWithExpenseAccounts();
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
            'payment_method' => 'business_account',
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
                ->where('prefill.supplier_id', $supplier->id));
    }
}
