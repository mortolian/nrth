<?php

namespace App\Modules\Wealth\Models;

use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WealthPortfolio extends Model
{
    use HasTeamScope;
    use SoftDeletes;

    protected $table = 'wealth_portfolios';

    protected $fillable = [
        'team_id',
        'name',
        'base_currency',
        'financial_year_start_month',
        'notes',
        'is_default',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'financial_year_start_month' => 'integer',
            'is_default' => 'boolean',
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
     * @return HasMany<WealthAsset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(WealthAsset::class, 'portfolio_id');
    }

    /**
     * @return HasMany<WealthContributionAllowance, $this>
     */
    public function contributionAllowances(): HasMany
    {
        return $this->hasMany(WealthContributionAllowance::class, 'portfolio_id');
    }
}
