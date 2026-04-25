<?php

namespace App\Domain\Tax\Models;

use App\Domain\Shared\HasTeamScope;
use Database\Factories\TaxRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxRate extends Model
{
    /** @use HasFactory<TaxRateFactory> */
    use HasFactory;
    use HasTeamScope;

    protected $table = 'tax_rates';

    protected $fillable = [
        'team_id',
        'name',
        'rate',
        'rate_percent',
        'code',
        'is_default',
        'is_exempt',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'rate_percent' => 'decimal:2',
            'is_default' => 'boolean',
            'is_exempt' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Team::class);
    }

    protected static function newFactory(): TaxRateFactory
    {
        return TaxRateFactory::new();
    }
}
