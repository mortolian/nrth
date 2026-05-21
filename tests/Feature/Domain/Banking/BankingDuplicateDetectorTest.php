<?php

namespace Tests\Feature\Domain\Banking;

use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Models\BankingTransaction;
use App\Domain\Banking\Services\BankingDuplicateDetector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankingDuplicateDetectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_existing_duplicate_keys(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $account = BankingAccount::factory()->for($team)->create();
        $detector = app(BankingDuplicateDetector::class);

        $key = $detector->duplicateKey(
            $account->id,
            '2026-01-01',
            '100.00',
            'Test payment',
            'REF-A'
        );

        BankingTransaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'account_id' => $account->id,
            'transaction_date' => '2026-01-01',
            'description' => 'Test payment',
            'reference' => 'REF-A',
            'amount' => '100.00',
            'currency' => 'ZAR',
            'direction' => 'debit',
            'source_hash' => hash('sha256', 'src'),
            'duplicate_key' => $key,
        ]);

        $existing = $detector->existingKeysForAccount($account->id, [$key, 'nonexistent']);

        $this->assertArrayHasKey($key, $existing);
        $this->assertArrayNotHasKey('nonexistent', $existing);
    }

    public function test_duplicate_key_is_deterministic(): void
    {
        $detector = app(BankingDuplicateDetector::class);

        $a = $detector->duplicateKey(1, '2026-01-01', '50.00', 'Payment', 'X');
        $b = $detector->duplicateKey(1, '2026-01-01', '50.00', 'Payment', 'X');

        $this->assertSame($a, $b);
        $this->assertNotSame(
            $a,
            $detector->duplicateKey(2, '2026-01-01', '50.00', 'Payment', 'X')
        );
    }
}
