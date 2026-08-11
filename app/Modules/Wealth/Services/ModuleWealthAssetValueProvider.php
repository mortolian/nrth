<?php

namespace App\Modules\Wealth\Services;

use App\Modules\Wealth\Contracts\WealthAssetValueProvider;
use App\Modules\Wealth\Models\WealthAsset;
use Brick\Money\Money;
use Carbon\CarbonInterface;

final class ModuleWealthAssetValueProvider implements WealthAssetValueProvider
{
    public function assetsForTeam(int $teamId, CarbonInterface $asOf): array
    {
        $assets = WealthAsset::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $rows = [];
        foreach ($assets as $asset) {
            $cents = $asset->valueCentsAsOf($asOf);
            $rows[] = [
                'id' => $asset->id,
                'name' => $asset->name,
                'owner_name' => $asset->owner_name,
                'liquidity' => $asset->liquidity->value,
                'currency' => $asset->currency,
                'value' => Money::ofMinor($cents, $asset->currency),
            ];
        }

        return $rows;
    }
}
