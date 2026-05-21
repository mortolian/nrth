<?php

namespace Database\Factories;

use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Models\BankingTransaction;
use App\Domain\Banking\Services\BankingDuplicateDetector;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankingTransaction>
 */
class BankingTransactionFactory extends Factory
{
    protected $model = BankingTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $accountId = BankingAccount::factory();
        $date = fake()->date();
        $amount = fake()->randomFloat(2, 10, 5000);
        $description = fake()->sentence(3);
        $reference = fake()->optional()->uuid();

        return [
            'team_id' => Team::factory(),
            'account_id' => $accountId,
            'transaction_date' => $date,
            'description' => $description,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => 'ZAR',
            'direction' => TransactionDirection::Debit,
            'source_hash' => hash('sha256', $description.$amount),
            'duplicate_key' => app(BankingDuplicateDetector::class)->duplicateKey(
                1,
                $date,
                (string) $amount,
                $description,
                $reference
            ),
        ];
    }
}
