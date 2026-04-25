<?php

namespace App\Domain\Tax\Models;

use App\Domain\Shared\HasTeamScope;
use Database\Factories\VATReturnFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VATReturn extends Model
{
    /** @use HasFactory<VATReturnFactory> */
    use HasFactory;
    use HasTeamScope;

    protected $table = 'vat_returns';

    protected $fillable = [
        'team_id',
        'tax_period_id',
        'output_vat_cents',
        'input_vat_cents',
        'net_vat_cents',
        'period_start',
        'period_end',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'output_vat_cents' => 'integer',
            'input_vat_cents' => 'integer',
            'net_vat_cents' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Team::class);
    }

    public function taxPeriod(): BelongsTo
    {
        return $this->belongsTo(TaxPeriod::class);
    }

    protected static function newFactory(): VATReturnFactory
    {
        return VATReturnFactory::new();
    }
}
