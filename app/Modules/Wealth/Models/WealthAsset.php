<?php

namespace App\Modules\Wealth\Models;

use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use App\Modules\Wealth\Enums\WealthAssetType;
use App\Modules\Wealth\Enums\WealthLiquidity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WealthAsset extends Model
{
    use HasTeamScope;
    use SoftDeletes;

    protected $table = 'wealth_assets';

    protected $fillable = [
        'team_id',
        'portfolio_id',
        'name',
        'owner_name',
        'asset_type',
        'institution',
        'currency',
        'liquidity',
        'interest_rate_bps',
        'notes',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'asset_type' => WealthAssetType::class,
            'liquidity' => WealthLiquidity::class,
            'interest_rate_bps' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<WealthPortfolio, $this>
     */
    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(WealthPortfolio::class, 'portfolio_id');
    }

    /**
     * @return HasMany<WealthAssetValuation, $this>
     */
    public function valuations(): HasMany
    {
        return $this->hasMany(WealthAssetValuation::class, 'asset_id')->orderByDesc('valued_on')->orderByDesc('id');
    }

    /**
     * @return HasMany<WealthAssetTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(WealthAssetTransaction::class, 'asset_id')->orderByDesc('occurred_on')->orderByDesc('id');
    }

    /**
     * @return HasMany<WealthContributionAllowance, $this>
     */
    public function contributionAllowances(): HasMany
    {
        return $this->hasMany(WealthContributionAllowance::class, 'asset_id');
    }

    public function latestValuation(): ?WealthAssetValuation
    {
        return $this->valuations()
            ->reorder()
            ->orderByDesc('valued_on')
            ->orderByDesc('id')
            ->first();
    }

    public function valuationAsOf(CarbonInterface $date): ?WealthAssetValuation
    {
        return $this->valuations()
            ->reorder()
            ->whereDate('valued_on', '<=', $date->toDateString())
            ->orderByDesc('valued_on')
            ->orderByDesc('id')
            ->first();
    }

    public function currentValueCents(): int
    {
        return (int) ($this->latestValuation()?->value_cents ?? 0);
    }

    public function valueCentsAsOf(CarbonInterface $date): int
    {
        return (int) ($this->valuationAsOf($date)?->value_cents ?? 0);
    }
}
