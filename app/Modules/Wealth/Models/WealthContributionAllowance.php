<?php

namespace App\Modules\Wealth\Models;

use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WealthContributionAllowance extends Model
{
    use HasTeamScope;

    protected $table = 'wealth_contribution_allowances';

    protected $fillable = [
        'team_id',
        'portfolio_id',
        'asset_id',
        'owner_name',
        'label',
        'scheme_key',
        'financial_year_label',
        'year_starts_on',
        'year_ends_on',
        'limit_cents',
        'currency',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year_starts_on' => 'date',
            'year_ends_on' => 'date',
            'limit_cents' => 'integer',
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
     * @return BelongsTo<WealthAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(WealthAsset::class, 'asset_id');
    }
}
