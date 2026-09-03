<?php

namespace Tests\Feature\Banking;

use App\Domain\Banking\Enums\ImportStatus;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Models\BankingStatementImport;
use App\Domain\Banking\Models\BankingTransaction;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BankingStatementImportTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    /**
     * @return array{0: User, 1: Team, 2: BankingAccount}
     */
    private function teamWithImportAccount(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $account = BankingAccount::factory()->for($team)->create([
            'name' => 'Main Cheque',
            'currency' => 'ZAR',
        ]);

        return [$user, $team, $account];
    }

    /**
     * @param  array<string, string>  $mapping
     */
    private function mapAndConfirm(BankingStatementImport $import, array $mapping = []): void
    {
        $this->post(route('banking.import.map.store', $import), [
            'mapping' => $mapping ?: [
                'transaction_date' => 'Date',
                'description' => 'Description',
                'amount' => 'Amount',
                'reference' => 'Reference',
            ],
            'delimiter' => ',',
        ])->assertRedirect(route('banking.import.preview', $import));

        $this->post(route('banking.import.confirm', $import))
            ->assertRedirect(route('banking.transactions.index', ['account_id' => $import->account_id]));
    }

    private function uploadedCsv(string $path, string $filename = 'statement.csv'): UploadedFile
    {
        return new UploadedFile($path, $filename, 'text/csv', null, true);
    }

    public function test_csv_import_saves_file_and_transactions(): void
    {
        Storage::fake('local');
        [, $team, $account] = $this->teamWithImportAccount();

        $this->post(route('banking.import.store'), [
            'account_id' => $account->id,
            'file' => $this->uploadedCsv(base_path('tests/Fixtures/bank-statements/sample-signed-amount.csv')),
        ])->assertRedirect();

        $import = BankingStatementImport::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($import);
        Storage::disk('local')->assertExists($import->stored_path);

        $this->mapAndConfirm($import);

        $import->refresh();
        $this->assertSame(ImportStatus::Imported, $import->status);
        $this->assertSame(3, $import->imported_rows);

        $transactions = BankingTransaction::queryWithoutTeamScope()
            ->where('account_id', $account->id)
            ->get();

        $this->assertCount(3, $transactions);
        $this->assertTrue($transactions->every(fn ($t) => $t->team_id === $team->id));
        $this->assertTrue($transactions->every(fn ($t) => $t->banking_statement_import_id === $import->id));

        $account->refresh();
        $this->assertNotNull($account->csv_mapping_profile);
        $this->assertSame('Date', $account->csv_mapping_profile['mapping']['transaction_date'] ?? null);
    }

    public function test_same_transaction_not_imported_twice(): void
    {
        Storage::fake('local');
        [, $team, $account] = $this->teamWithImportAccount();

        $firstPath = base_path('tests/Fixtures/bank-statements/sample-signed-amount.csv');
        $secondPath = tempnam(sys_get_temp_dir(), 'nrth-stmt-');
        $this->assertNotFalse($secondPath);
        file_put_contents(
            $secondPath,
            file_get_contents($firstPath)."\n# same rows, different file hash\n"
        );

        try {
            $this->post(route('banking.import.store'), [
                'account_id' => $account->id,
                'file' => $this->uploadedCsv($firstPath, 'first.csv'),
            ]);
            $firstImport = BankingStatementImport::queryWithoutTeamScope()
                ->where('team_id', $team->id)
                ->latest('id')
                ->first();
            $this->mapAndConfirm($firstImport);

            $response = $this->post(route('banking.import.store'), [
                'account_id' => $account->id,
                'file' => $this->uploadedCsv($secondPath, 'second.csv'),
            ]);
            $response->assertRedirect();
            $this->assertStringContainsString('/preview', (string) $response->headers->get('Location'));

            $secondImport = BankingStatementImport::queryWithoutTeamScope()
                ->where('team_id', $team->id)
                ->latest('id')
                ->first();
            $this->assertNotNull($secondImport);
            $this->assertSame(ImportStatus::Parsed, $secondImport->status);
            $this->assertNotSame($firstImport->id, $secondImport->id);

            $this->post(route('banking.import.confirm', $secondImport));

            $count = BankingTransaction::queryWithoutTeamScope()
                ->where('account_id', $account->id)
                ->count();

            $this->assertSame(3, $count);

            $secondImport->refresh();
            $this->assertSame(0, $secondImport->imported_rows);
            $this->assertSame(3, $secondImport->duplicate_rows);
        } finally {
            @unlink($secondPath);
        }
    }

    public function test_second_upload_with_matching_headers_skips_map(): void
    {
        Storage::fake('local');
        [, $team, $account] = $this->teamWithImportAccount();

        $path = base_path('tests/Fixtures/bank-statements/sample-signed-amount.csv');

        $this->post(route('banking.import.store'), [
            'account_id' => $account->id,
            'file' => $this->uploadedCsv($path, 'first.csv'),
        ]);
        $firstImport = BankingStatementImport::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->latest('id')
            ->first();
        $this->mapAndConfirm($firstImport);

        $variantPath = tempnam(sys_get_temp_dir(), 'nrth-stmt-');
        $this->assertNotFalse($variantPath);
        file_put_contents(
            $variantPath,
            "Date,Description,Amount,Reference\n2026-02-01,Coffee,-40.00,REF100\n"
        );

        try {
            $response = $this->post(route('banking.import.store'), [
                'account_id' => $account->id,
                'file' => $this->uploadedCsv($variantPath, 'second.csv'),
            ]);

            $response->assertRedirect();
            $this->assertStringContainsString('/preview', (string) $response->headers->get('Location'));
            $this->assertTrue($response->getSession()->get('mapping_from_profile'));

            $secondImport = BankingStatementImport::queryWithoutTeamScope()
                ->where('team_id', $team->id)
                ->latest('id')
                ->first();
            $this->assertSame(ImportStatus::Parsed, $secondImport->status);
        } finally {
            @unlink($variantPath);
        }
    }

    public function test_different_headers_still_require_map(): void
    {
        Storage::fake('local');
        [, $team, $account] = $this->teamWithImportAccount();

        $this->post(route('banking.import.store'), [
            'account_id' => $account->id,
            'file' => $this->uploadedCsv(base_path('tests/Fixtures/bank-statements/sample-signed-amount.csv')),
        ]);
        $firstImport = BankingStatementImport::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->latest('id')
            ->first();
        $this->mapAndConfirm($firstImport);

        $response = $this->post(route('banking.import.store'), [
            'account_id' => $account->id,
            'file' => $this->uploadedCsv(
                base_path('tests/Fixtures/bank-statements/sample-debit-credit.csv'),
                'debit-credit.csv'
            ),
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('/map', (string) $response->headers->get('Location'));

        $secondImport = BankingStatementImport::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->latest('id')
            ->first();
        $this->assertSame(ImportStatus::Pending, $secondImport->status);
    }

    public function test_change_mapping_from_preview_updates_profile(): void
    {
        Storage::fake('local');
        [, $team, $account] = $this->teamWithImportAccount();

        $this->post(route('banking.import.store'), [
            'account_id' => $account->id,
            'file' => $this->uploadedCsv(base_path('tests/Fixtures/bank-statements/sample-signed-amount.csv')),
        ]);
        $import = BankingStatementImport::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->latest('id')
            ->first();

        $this->post(route('banking.import.map.store', $import), [
            'mapping' => [
                'transaction_date' => 'Date',
                'description' => 'Description',
                'amount' => 'Amount',
            ],
            'delimiter' => ',',
        ])->assertRedirect(route('banking.import.preview', $import));

        $account->refresh();
        $this->assertSame('Amount', $account->csv_mapping_profile['mapping']['amount'] ?? null);
        $this->assertArrayNotHasKey('reference', array_filter($account->csv_mapping_profile['mapping']));

        $this->get(route('banking.import.map', $import))->assertOk();

        $this->post(route('banking.import.map.store', $import), [
            'mapping' => [
                'transaction_date' => 'Date',
                'description' => 'Description',
                'amount' => 'Amount',
                'reference' => 'Reference',
            ],
            'delimiter' => ',',
        ])->assertRedirect(route('banking.import.preview', $import));

        $account->refresh();
        $this->assertSame('Reference', $account->csv_mapping_profile['mapping']['reference'] ?? null);
    }

    public function test_undo_import_soft_deletes_file_and_allows_reimport_from_history(): void
    {
        Storage::fake('local');
        [, $team, $account] = $this->teamWithImportAccount();
        $path = base_path('tests/Fixtures/bank-statements/sample-signed-amount.csv');

        $this->post(route('banking.import.store'), [
            'account_id' => $account->id,
            'file' => $this->uploadedCsv($path),
        ]);
        $import = BankingStatementImport::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->latest('id')
            ->first();
        $this->mapAndConfirm($import);

        $activePath = $import->fresh()->stored_path;
        Storage::disk('local')->assertExists($activePath);

        $this->assertSame(3, BankingTransaction::queryWithoutTeamScope()->where('account_id', $account->id)->count());

        $this->post(route('banking.imports.undo', $import))
            ->assertRedirect()
            ->assertSessionHas('success');

        $import->refresh();
        $this->assertSame(ImportStatus::Undone, $import->status);
        $this->assertSame(0, BankingTransaction::queryWithoutTeamScope()->where('account_id', $account->id)->count());
        Storage::disk('local')->assertMissing($activePath);
        Storage::disk('local')->assertExists($import->stored_path);
        $this->assertStringContainsString('/deleted/', $import->stored_path);
        $this->assertSame($activePath, $import->metadata['active_stored_path'] ?? null);

        $this->get(route('banking.imports.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('imports.data.0.can_reimport', true)
                ->where('imports.data.0.can_undo', false));

        $response = $this->post(route('banking.imports.reimport', $import));
        $response->assertRedirect(route('banking.import.preview', $import));

        $import->refresh();
        $this->assertSame(ImportStatus::Parsed, $import->status);
        $this->assertStringNotContainsString('/deleted/', $import->stored_path);
        Storage::disk('local')->assertExists($import->stored_path);

        $this->post(route('banking.import.confirm', $import))
            ->assertRedirect(route('banking.transactions.index', ['account_id' => $account->id]));

        $this->assertSame(3, BankingTransaction::queryWithoutTeamScope()->where('account_id', $account->id)->count());
        $import->refresh();
        $this->assertSame(ImportStatus::Imported, $import->status);
    }

    public function test_undo_still_allows_fresh_upload_of_same_file(): void
    {
        Storage::fake('local');
        [, $team, $account] = $this->teamWithImportAccount();
        $path = base_path('tests/Fixtures/bank-statements/sample-signed-amount.csv');

        $this->post(route('banking.import.store'), [
            'account_id' => $account->id,
            'file' => $this->uploadedCsv($path),
        ]);
        $import = BankingStatementImport::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->latest('id')
            ->first();
        $this->mapAndConfirm($import);
        $this->post(route('banking.imports.undo', $import));

        $response = $this->post(route('banking.import.store'), [
            'account_id' => $account->id,
            'file' => $this->uploadedCsv($path, 'reimport.csv'),
        ]);
        $response->assertSessionHasNoErrors();
        $this->assertStringContainsString('/preview', (string) $response->headers->get('Location'));
    }

    public function test_reimport_forbidden_when_file_missing(): void
    {
        Storage::fake('local');
        [, $team, $account] = $this->teamWithImportAccount();

        $this->post(route('banking.import.store'), [
            'account_id' => $account->id,
            'file' => $this->uploadedCsv(base_path('tests/Fixtures/bank-statements/sample-signed-amount.csv')),
        ]);
        $import = BankingStatementImport::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->latest('id')
            ->first();
        $this->mapAndConfirm($import);
        $this->post(route('banking.imports.undo', $import));

        $import->refresh();
        Storage::disk('local')->delete($import->stored_path);

        $this->post(route('banking.imports.reimport', $import))
            ->assertSessionHasErrors('import');
    }

    public function test_undo_forbidden_for_non_imported_status(): void
    {
        Storage::fake('local');
        [, $team, $account] = $this->teamWithImportAccount();

        $this->post(route('banking.import.store'), [
            'account_id' => $account->id,
            'file' => $this->uploadedCsv(base_path('tests/Fixtures/bank-statements/sample-signed-amount.csv')),
        ]);
        $import = BankingStatementImport::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->latest('id')
            ->first();

        $this->post(route('banking.imports.undo', $import))
            ->assertSessionHasErrors('import');
    }

    public function test_undo_forbidden_for_other_team(): void
    {
        Storage::fake('local');
        [, $team, $account] = $this->teamWithImportAccount();

        $this->post(route('banking.import.store'), [
            'account_id' => $account->id,
            'file' => $this->uploadedCsv(base_path('tests/Fixtures/bank-statements/sample-signed-amount.csv')),
        ]);
        $import = BankingStatementImport::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->latest('id')
            ->first();
        $this->mapAndConfirm($import);

        $otherUser = User::factory()->withPersonalTeam()->create();
        $this->actingTeamContext($otherUser, $otherUser->currentTeam);

        $this->post(route('banking.imports.undo', $import))->assertNotFound();
    }

    public function test_import_history_lists_imports(): void
    {
        Storage::fake('local');
        [, $team, $account] = $this->teamWithImportAccount();

        $this->post(route('banking.import.store'), [
            'account_id' => $account->id,
            'file' => $this->uploadedCsv(base_path('tests/Fixtures/bank-statements/sample-signed-amount.csv')),
        ]);
        $import = BankingStatementImport::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->latest('id')
            ->first();
        $this->mapAndConfirm($import);

        $this->get(route('banking.imports.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Banking/Import/History')
                ->has('imports.data', 1)
                ->where('imports.data.0.id', $import->id)
                ->where('imports.data.0.can_undo', true)
                ->where('imports.data.0.can_delete', false));
    }

    public function test_history_filters_by_status(): void
    {
        [, $team, $account] = $this->teamWithImportAccount();

        $imported = BankingStatementImport::factory()->for($team)->for($account, 'account')->create([
            'status' => ImportStatus::Imported,
            'original_filename' => 'imported.csv',
        ]);
        $undone = BankingStatementImport::factory()->for($team)->for($account, 'account')->create([
            'status' => ImportStatus::Undone,
            'original_filename' => 'undone.csv',
        ]);

        $this->get(route('banking.imports.index', ['status' => 'undone']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('imports.data', 1)
                ->where('imports.data.0.id', $undone->id)
                ->where('filters.status', 'undone'));

        $this->get(route('banking.imports.index', ['status' => 'imported']))
            ->assertInertia(fn ($page) => $page
                ->has('imports.data', 1)
                ->where('imports.data.0.id', $imported->id));
    }

    public function test_can_delete_undone_import_and_stored_file(): void
    {
        Storage::fake('local');
        [, $team, $account] = $this->teamWithImportAccount();

        $storedPath = sprintf('banking/%d/deleted/2026/06/statement.csv', $account->id);
        Storage::disk('local')->put($storedPath, 'statement-contents');

        $import = BankingStatementImport::factory()->for($team)->for($account, 'account')->create([
            'status' => ImportStatus::Undone,
            'stored_path' => $storedPath,
            'original_filename' => 'june-statement.csv',
        ]);

        $this->get(route('banking.imports.index'))
            ->assertInertia(fn ($page) => $page
                ->where('imports.data.0.can_delete', true)
                ->where('imports.data.0.can_reimport', true));

        $this->delete(route('banking.imports.destroy', $import))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNull(BankingStatementImport::queryWithoutTeamScope()->find($import->id));
        Storage::disk('local')->assertMissing($storedPath);
    }

    public function test_can_delete_pending_reimport_statement(): void
    {
        Storage::fake('local');
        [, $team, $account] = $this->teamWithImportAccount();

        $storedPath = sprintf('banking/%d/2026/06/restored.csv', $account->id);
        Storage::disk('local')->put($storedPath, 'statement-contents');

        $import = BankingStatementImport::factory()->for($team)->for($account, 'account')->create([
            'status' => ImportStatus::Pending,
            'stored_path' => $storedPath,
            'metadata' => ['reimported_at' => now()->toIso8601String()],
        ]);

        $this->delete(route('banking.imports.destroy', $import))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNull(BankingStatementImport::queryWithoutTeamScope()->find($import->id));
        Storage::disk('local')->assertMissing($storedPath);
    }

    public function test_cannot_delete_imported_statement(): void
    {
        Storage::fake('local');
        [, $team, $account] = $this->teamWithImportAccount();

        $storedPath = sprintf('banking/%d/2026/06/live.csv', $account->id);
        Storage::disk('local')->put($storedPath, 'statement-contents');

        $import = BankingStatementImport::factory()->for($team)->for($account, 'account')->create([
            'status' => ImportStatus::Imported,
            'stored_path' => $storedPath,
        ]);

        $this->delete(route('banking.imports.destroy', $import))
            ->assertSessionHasErrors('import');

        $this->assertNotNull(BankingStatementImport::queryWithoutTeamScope()->find($import->id));
        Storage::disk('local')->assertExists($storedPath);
    }

    public function test_cannot_delete_other_team_import(): void
    {
        Storage::fake('local');
        [, $team, $account] = $this->teamWithImportAccount();

        $import = BankingStatementImport::factory()->for($team)->for($account, 'account')->create([
            'status' => ImportStatus::Undone,
        ]);

        $otherUser = User::factory()->withPersonalTeam()->create();
        $this->actingTeamContext($otherUser, $otherUser->currentTeam);

        $this->delete(route('banking.imports.destroy', $import))->assertNotFound();
        $this->assertNotNull(BankingStatementImport::queryWithoutTeamScope()->find($import->id));
    }

    public function test_rejects_duplicate_file_hash_after_successful_import(): void
    {
        Storage::fake('local');
        [, , $account] = $this->teamWithImportAccount();

        $path = base_path('tests/Fixtures/bank-statements/sample-signed-amount.csv');

        $this->post(route('banking.import.store'), [
            'account_id' => $account->id,
            'file' => $this->uploadedCsv($path),
        ]);

        $import = BankingStatementImport::query()->latest('id')->first();
        $this->mapAndConfirm($import);

        $response = $this->post(route('banking.import.store'), [
            'account_id' => $account->id,
            'file' => $this->uploadedCsv($path),
        ]);

        $response->assertSessionHasErrors('file');
    }

    public function test_ofx_import_flow(): void
    {
        Storage::fake('local');
        [, $team, $account] = $this->teamWithImportAccount();

        $file = new UploadedFile(
            base_path('tests/Fixtures/bank-statements/sample.ofx'),
            'statement.ofx',
            'application/x-ofx',
            null,
            true
        );

        $response = $this->from(route('banking.import.create'))
            ->post(route('banking.import.store'), [
                'account_id' => $account->id,
                'file' => $file,
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect();

        $import = BankingStatementImport::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($import);
        $this->assertSame('ofx', $import->file_type);
        $this->assertStringContainsString('/preview', (string) $response->headers->get('Location'));
        $import->refresh();
        $this->assertSame(ImportStatus::Parsed, $import->status);

        $this->post(route('banking.import.confirm', $import));

        $this->assertSame(2, BankingTransaction::queryWithoutTeamScope()->where('account_id', $account->id)->count());
    }
}
