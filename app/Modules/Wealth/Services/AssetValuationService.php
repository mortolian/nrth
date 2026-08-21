<?php

namespace App\Modules\Wealth\Services;

use App\Modules\Wealth\Enums\WealthValuationSource;
use App\Modules\Wealth\Models\WealthAsset;
use App\Modules\Wealth\Models\WealthAssetValuation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AssetValuationService
{
    public function record(
        WealthAsset $asset,
        CarbonInterface $valuedOn,
        int $valueCents,
        ?string $notes = null,
        WealthValuationSource $source = WealthValuationSource::Manual,
    ): WealthAssetValuation {
        if ($valueCents < 0) {
            throw new InvalidArgumentException('Valuation cannot be negative.');
        }

        return DB::transaction(function () use ($asset, $valuedOn, $valueCents, $notes, $source): WealthAssetValuation {
            return WealthAssetValuation::query()->updateOrCreate(
                [
                    'asset_id' => $asset->id,
                    'valued_on' => $valuedOn->toDateString(),
                ],
                [
                    'team_id' => $asset->team_id,
                    'value_cents' => $valueCents,
                    'currency' => $asset->currency,
                    'notes' => $notes,
                    'source' => $source,
                ]
            );
        });
    }

    public function update(
        WealthAssetValuation $valuation,
        CarbonInterface $valuedOn,
        int $valueCents,
        ?string $notes = null,
    ): WealthAssetValuation {
        if ($valueCents < 0) {
            throw new InvalidArgumentException('Valuation cannot be negative.');
        }

        $valuation->fill([
            'valued_on' => $valuedOn->toDateString(),
            'value_cents' => $valueCents,
            'notes' => $notes,
        ]);
        $valuation->save();

        return $valuation;
    }
}
