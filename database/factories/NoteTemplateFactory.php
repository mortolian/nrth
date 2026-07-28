<?php

namespace Database\Factories;

use App\Domain\Invoicing\Models\NoteTemplate;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NoteTemplate>
 */
class NoteTemplateFactory extends Factory
{
    protected $model = NoteTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => 'Banking details',
            'body' => "**Bank:** Example Bank\n**Account:** 123456789",
            'target' => 'notes',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
