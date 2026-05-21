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

    public function test_csv_import_saves_file_and_transactions(): void
    {
        Storage::fake('local');
        [, $team, $account] = $this->teamWithImportAccount();

        $file = new UploadedFile(
            base_path('tests/Fixtures/bank-statements/sample-signed-amount.csv'),
            'statement.csv',
            'text/csv',
            null,
            true
        );

        $this->post(route('banking.import.store'), [
            'account_id' => $account->id,
            'file' => $file,
        ])->assertRedirect();

        $import = BankingStatementImport::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($import);
        Storage::disk('local')->assertExists($import->stored_path);

        $this->post(route('banking.import.map.store', $import), [
            'mapping' => [
                'transaction_date' => 'Date',
                'description' => 'Description',
                'amount' => 'Amount',
                'reference' => 'Reference',
            ],
            'delimiter' => ',',
        ])->assertRedirect(route('banking.import.preview', $import));

        $import->refresh();
        $this->assertSame(ImportStatus::Parsed, $import->status);

        $this->post(route('banking.import.confirm', $import))
            ->assertRedirect(route('banking.transactions.index', ['account_id' => $account->id]));

        $import->refresh();
        $this->assertSame(ImportStatus::Imported, $import->status);
        $this->assertSame(3, $import->imported_rows);

        $transactions = BankingTransaction::queryWithoutTeamScope()
            ->where('account_id', $account->id)
            ->get();

        $this->assertCount(3, $transactions);
        $this->assertTrue($transactions->every(fn ($t) => $t->team_id === $team->id));
        $this->assertTrue($transactions->every(fn ($t) => $t->banking_statement_import_id === $import->id));
    }

    public function test_same_transaction_not_imported_twice(): void
    {
        Storage::fake('local');
        [, $team, $account] = $this->teamWithImportAccount();

        $mapping = [
            'mapping' => [
                'transaction_date' => 'Date',
                'description' => 'Description',
                'amount' => 'Amount',
            ],
            'delimiter' => ',',
        ];

        foreach (['first.csv', 'second.csv'] as $filename) {
            $file = new UploadedFile(
                base_path('tests/Fixtures/bank-statements/sample-signed-amount.csv'),
                $filename,
                'text/csv',
                null,
                true
            );

            $this->post(route('banking.import.store'), [
                'account_id' => $account->id,
                'file' => $file,
            ]);

            $import = BankingStatementImport::queryWithoutTeamScope()
                ->where('team_id', $team->id)
                ->latest('id')
                ->first();

            $this->post(route('banking.import.map.store', $import), $mapping);
            $this->post(route('banking.import.confirm', $import));
        }

        $count = BankingTransaction::queryWithoutTeamScope()
            ->where('account_id', $account->id)
            ->count();

        $this->assertSame(3, $count);

        $secondImport = BankingStatementImport::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->latest('id')
            ->first();

        $this->assertSame(0, $secondImport->imported_rows);
        $this->assertSame(3, $secondImport->duplicate_rows);
    }

    public function test_rejects_duplicate_file_hash_after_successful_import(): void
    {
        Storage::fake('local');
        [, , $account] = $this->teamWithImportAccount();

        $path = base_path('tests/Fixtures/bank-statements/sample-signed-amount.csv');
        $file = new UploadedFile($path, 'statement.csv', 'text/csv', null, true);

        $this->post(route('banking.import.store'), [
            'account_id' => $account->id,
            'file' => $file,
        ]);

        $import = BankingStatementImport::query()->latest('id')->first();
        $this->post(route('banking.import.map.store', $import), [
            'mapping' => [
                'transaction_date' => 'Date',
                'description' => 'Description',
                'amount' => 'Amount',
            ],
            'delimiter' => ',',
        ]);
        $this->post(route('banking.import.confirm', $import));

        $response = $this->post(route('banking.import.store'), [
            'account_id' => $account->id,
            'file' => new UploadedFile($path, 'statement.csv', 'text/csv', null, true),
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
