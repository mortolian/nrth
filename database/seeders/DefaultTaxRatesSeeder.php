<?php

namespace Database\Seeders;

use App\Domain\Accounting\Models\TaxRate;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

class DefaultTaxRatesSeeder
{
    public function runForTeam(Team $team): void
    {
        DB::transaction(function () use ($team): void {
            $rows = [
                ['name' => 'VAT 15%', 'code' => 'VAT15', 'rate_percent' => '15.00', 'is_exempt' => false],
                ['name' => 'VAT 0%', 'code' => 'VAT0', 'rate_percent' => '0.00', 'is_exempt' => false],
                ['name' => 'VAT Exempt', 'code' => 'VAT-EXEMPT', 'rate_percent' => null, 'is_exempt' => true],
            ];

            foreach ($rows as $row) {
                TaxRate::queryWithoutTeamScope()->updateOrCreate(
                    [
                        'team_id' => $team->id,
                        'code' => $row['code'],
                    ],
                    [
                        'name' => $row['name'],
                        'rate_percent' => $row['rate_percent'],
                        'is_exempt' => $row['is_exempt'],
                        'is_active' => true,
                    ]
                );
            }
        });
    }
}
