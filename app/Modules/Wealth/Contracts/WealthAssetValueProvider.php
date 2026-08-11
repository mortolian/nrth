<?php

namespace App\Modules\Wealth\Contracts;

use Brick\Money\Money;
use Carbon\CarbonInterface;

interface WealthAssetValueProvider
{
    /**
     * @return list<array{id: int, name: string, owner_name: string, liquidity: string, currency: string, value: Money}>
     */
    public function assetsForTeam(int $teamId, CarbonInterface $asOf): array;
}
