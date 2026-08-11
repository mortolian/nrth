<?php

namespace App\Modules\Wealth\Services;

use App\Modules\Wealth\Enums\WealthTransactionType;
use App\Modules\Wealth\Models\WealthAssetTransaction;
use App\Modules\Wealth\Models\WealthContributionAllowance;
use App\Modules\Wealth\Models\WealthPortfolio;

final class ContributionAllowanceService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listForPortfolio(WealthPortfolio $portfolio): array
    {
        return $portfolio->contributionAllowances()
            ->with('asset:id,name')
            ->orderByDesc('year_starts_on')
            ->get()
            ->map(fn (WealthContributionAllowance $row) => $this->present($row))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(WealthContributionAllowance $allowance): array
    {
        $contributed = $this->contributedCents($allowance);
        $remaining = max(0, (int) $allowance->limit_cents - $contributed);

        return [
            'id' => $allowance->id,
            'portfolio_id' => $allowance->portfolio_id,
            'asset_id' => $allowance->asset_id,
            'asset_name' => $allowance->asset?->name,
            'owner_name' => $allowance->owner_name,
            'label' => $allowance->label,
            'scheme_key' => $allowance->scheme_key,
            'financial_year_label' => $allowance->financial_year_label,
            'year_starts_on' => $allowance->year_starts_on->toDateString(),
            'year_ends_on' => $allowance->year_ends_on->toDateString(),
            'limit_cents' => (int) $allowance->limit_cents,
            'contributed_cents' => $contributed,
            'remaining_cents' => $remaining,
            'currency' => $allowance->currency,
            'notes' => $allowance->notes,
        ];
    }

    public function contributedCents(WealthContributionAllowance $allowance): int
    {
        $query = WealthAssetTransaction::query()
            ->where('type', WealthTransactionType::Contribution)
            ->whereDate('occurred_on', '>=', $allowance->year_starts_on->toDateString())
            ->whereDate('occurred_on', '<=', $allowance->year_ends_on->toDateString());

        if ($allowance->asset_id !== null) {
            $query->where('asset_id', $allowance->asset_id);
        } else {
            $assetIds = WealthPortfolio::query()
                ->find($allowance->portfolio_id)
                ?->assets()
                ->where('owner_name', $allowance->owner_name)
                ->pluck('id') ?? collect();
            $query->whereIn('asset_id', $assetIds);
        }

        return (int) $query->sum('amount_cents');
    }
}
