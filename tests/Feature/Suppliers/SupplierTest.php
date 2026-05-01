<?php

namespace Tests\Feature\Suppliers;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\Transaction;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_suppliers_index_create_show_update_and_delete_without_expenses(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $this->get(route('suppliers.index'))->assertOk();
        $this->get(route('suppliers.create'))->assertOk();

        $this->post(route('suppliers.store'), [
            'name' => 'Acme Supplies',
            'contact_name' => null,
            'email' => 'billing@acme.test',
            'phone' => null,
            'vat_number' => null,
            'registration_number' => null,
            'address' => null,
            'notes' => null,
            'is_active' => true,
        ])->assertRedirect();

        $supplier = Supplier::queryWithoutTeamScope()->where('team_id', $team->id)->where('name', 'Acme Supplies')->first();
        $this->assertNotNull($supplier);

        $this->get(route('suppliers.show', $supplier))->assertOk();

        $this->put(route('suppliers.update', $supplier), [
            'name' => 'Acme Supplies Ltd',
            'contact_name' => 'Pat',
            'email' => 'billing@acme.test',
            'phone' => null,
            'vat_number' => null,
            'registration_number' => null,
            'address' => null,
            'notes' => 'Preferred vendor',
            'is_active' => false,
        ])->assertRedirect(route('suppliers.show', $supplier->fresh()));

        $this->assertSame('Acme Supplies Ltd', $supplier->fresh()->name);
        $this->assertFalse($supplier->fresh()->is_active);

        $this->delete(route('suppliers.destroy', $supplier->fresh()))->assertRedirect(route('suppliers.index'));
        $this->assertNull(Supplier::queryWithoutTeamScope()->find($supplier->id));
    }

    public function test_supplier_cannot_be_deleted_when_linked_to_expense(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $supplier = Supplier::factory()->for($team)->create();

        Transaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'supplier_id' => $supplier->id,
            'type' => TransactionType::Expense,
            'status' => 'draft',
            'reference' => $supplier->name,
            'transaction_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);

        $this->from(route('suppliers.show', $supplier))
            ->delete(route('suppliers.destroy', $supplier))
            ->assertSessionHasErrors('delete');

        $this->assertNotNull(Supplier::queryWithoutTeamScope()->find($supplier->id));
    }

    public function test_expense_store_accepts_saved_supplier_id(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        Account::factory()->for($team)->expense()->create(['code' => '7500', 'name' => 'General expense']);
        Account::factory()->for($team)->asset()->create(['code' => '1010', 'name' => 'Bank', 'is_system' => true]);

        $supplier = Supplier::factory()->for($team)->create(['name' => 'Ledger Supplier']);

        $category = Account::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('type', AccountType::Expense)
            ->where('code', '7500')
            ->first();

        $this->assertNotNull($category);

        $this->post(route('expenses.store'), [
            'date' => '2026-05-01',
            'supplier_id' => $supplier->id,
            'category_account_id' => $category->id,
            'description' => 'Office supplies',
            'amount_excl_vat_cents' => 100_00,
            'vat_rate' => 'no_vat',
            'vat_amount_cents' => 0,
            'payment_method' => 'business_account',
        ])->assertRedirect(route('expenses.index'));

        $txn = Transaction::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('supplier_id', $supplier->id)
            ->first();

        $this->assertNotNull($txn);
        $this->assertSame('Ledger Supplier', $txn->reference);
    }

    public function test_other_team_cannot_view_supplier(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $this->assertNotNull($team);

        $intruder = User::factory()->withPersonalTeam()->create();
        $intruderTeam = $intruder->currentTeam;
        $this->assertNotNull($intruderTeam);

        $supplier = Supplier::factory()->for($team)->create();

        $this->actingTeamContext($intruder, $intruderTeam);
        $this->get(route('suppliers.show', $supplier))->assertNotFound();
    }
}
