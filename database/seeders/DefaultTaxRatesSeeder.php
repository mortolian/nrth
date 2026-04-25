<?php

namespace Database\Seeders;

use App\Domain\Tax\Models\TaxRate;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

class DefaultTaxRatesSeeder
{
    public function runForTeam(Team $team): void
    {
        DB::transaction(function () use ($team): void {
            $rows = [
                ['name' => 'VAT Standard Rate (15%)', 'code' => 'VAT15', 'rate' => '0.1500', 'rate_percent' => '15.00', 'is_exempt' => false, 'is_default' => true],
                ['name' => 'VAT Zero Rate (0%)', 'code' => 'VAT0', 'rate' => '0.0000', 'rate_percent' => '0.00', 'is_exempt' => false, 'is_default' => false],
                ['name' => 'VAT Exempt', 'code' => 'EXEMPT', 'rate' => null, 'rate_percent' => null, 'is_exempt' => true, 'is_default' => false],
                ['name' => 'VAT Outside Scope', 'code' => 'OUTSIDE_SCOPE', 'rate' => null, 'rate_percent' => null, 'is_exempt' => true, 'is_default' => false],
            ];

            foreach ($rows as $row) {
                TaxRate::queryWithoutTeamScope()->updateOrCreate(
                    [
                        'team_id' => $team->id,
                        'code' => $row['code'],
                    ],
                    [
                        'name' => $row['name'],
                        'rate' => $row['rate'],
                        'rate_percent' => $row['rate_percent'],
                        'is_default' => $row['is_default'],
                        'is_exempt' => $row['is_exempt'],
                        'is_active' => true,
                    ]
                );
            }
        });
    }
}
