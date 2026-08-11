<?php

namespace App\Modules\Wealth\Models;

use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use App\Modules\Wealth\Enums\WealthTransactionType;
use App\Modules\Wealth\Enums\WealthValuationSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WealthAssetTransaction extends Model
{
    use HasTeamScope;

    protected $table = 'wealth_asset_transactions';

    protected $fillable = [
        'team_id',
        'asset_id',
        'type',
        'occurred_on',
        'amount_cents',
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
            'type' => WealthTransactionType::class,
            'occurred_on' => 'date',
            'amount_cents' => 'integer',
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

    public function signedFlowCents(): int
    {
        return $this->type->signedFlowCents((int) $this->amount_cents);
    }
}
