<?php

namespace Database\Factories;

use App\Domain\Banking\Models\BankingAccount;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankingAccount>
 */
class BankingAccountFactory extends Factory
{
    protected $model = BankingAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->words(3, true).' Account',
            'bank_name' => fake()->optional()->company(),
            'account_number_last4' => (string) fake()->numberBetween(1000, 9999),
            'currency' => 'ZAR',
            'type' => fake()->optional()->randomElement(['cheque', 'savings', 'credit_card']),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
