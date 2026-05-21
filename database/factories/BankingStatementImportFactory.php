<?php

namespace Database\Factories;

use App\Domain\Banking\Enums\ImportStatus;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Models\BankingStatementImport;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankingStatementImport>
 */
class BankingStatementImportFactory extends Factory
{
    protected $model = BankingStatementImport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'account_id' => BankingAccount::factory(),
            'original_filename' => 'statement.csv',
            'stored_path' => 'banking/1/2026/05/statement.csv',
            'file_type' => 'csv',
            'mime_type' => 'text/csv',
            'file_hash' => fake()->sha256(),
            'status' => ImportStatus::Pending,
        ];
    }
}
