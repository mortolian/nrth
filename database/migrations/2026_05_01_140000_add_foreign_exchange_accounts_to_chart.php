<?php

use App\Models\Team;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Team::query()->orderBy('id')->each(function (Team $team): void {
            (new DefaultChartOfAccountsSeeder)->runForTeam($team);
        });
    }

    public function down(): void
    {
        // System accounts are not removed automatically.
    }
};
