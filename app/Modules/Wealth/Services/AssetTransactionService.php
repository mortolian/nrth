<?php

namespace App\Modules\Wealth\Services;

use App\Modules\Wealth\Enums\WealthTransactionType;
use App\Modules\Wealth\Enums\WealthValuationSource;
use App\Modules\Wealth\Models\WealthAsset;
use App\Modules\Wealth\Models\WealthAssetTransaction;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class AssetTransactionService
{
    public function record(
        WealthAsset $asset,
        WealthTransactionType $type,
        CarbonInterface $occurredOn,
        int $amountCents,
        ?string $notes = null,
        WealthValuationSource $source = WealthValuationSource::Manual,
    ): WealthAssetTransaction {
        if ($type !== WealthTransactionType::Adjustment && $amountCents < 0) {
            throw new InvalidArgumentException('Amount must be non-negative for this transaction type.');
        }

        if ($type !== WealthTransactionType::Adjustment) {
            $amountCents = abs($amountCents);
        }

        return WealthAssetTransaction::query()->create([
            'team_id' => $asset->team_id,
            'asset_id' => $asset->id,
            'type' => $type,
            'occurred_on' => $occurredOn->toDateString(),
            'amount_cents' => $amountCents,
            'currency' => $asset->currency,
            'notes' => $notes,
            'source' => $source,
        ]);
    }

    public function update(
        WealthAssetTransaction $transaction,
        WealthTransactionType $type,
        CarbonInterface $occurredOn,
        int $amountCents,
        ?string $notes = null,
    ): WealthAssetTransaction {
        if ($type !== WealthTransactionType::Adjustment && $amountCents < 0) {
            throw new InvalidArgumentException('Amount must be non-negative for this transaction type.');
        }

        if ($type !== WealthTransactionType::Adjustment) {
            $amountCents = abs($amountCents);
        }

        $transaction->fill([
            'type' => $type,
            'occurred_on' => $occurredOn->toDateString(),
            'amount_cents' => $amountCents,
            'notes' => $notes,
        ]);
        $transaction->save();

        return $transaction;
    }
}
