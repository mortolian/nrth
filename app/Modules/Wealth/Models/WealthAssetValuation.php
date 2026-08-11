<?php

namespace App\Modules\Wealth\Models;

use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use App\Modules\Wealth\Enums\WealthValuationSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WealthAssetValuation extends Model
{
    use HasTeamScope;

    protected $table = 'wealth_asset_valuations';

    protected $fillable = [
        'team_id',
        'asset_id',
        'valued_on',
        'value_cents',
        'currency',
        'notes',
        'source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valued_on' => 'date',
            'value_cents' => 'integer',
            'source' => WealthValuationSource::class,
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
     * @return BelongsTo<WealthAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(WealthAsset::class, 'asset_id');
    }
}
