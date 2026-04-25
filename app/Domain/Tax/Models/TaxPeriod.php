<?php

namespace App\Domain\Tax\Models;

use App\Domain\Shared\HasTeamScope;
use App\Domain\Tax\Enums\TaxPeriodStatus;
use App\Domain\Tax\Enums\TaxPeriodType;
use Database\Factories\TaxPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TaxPeriod extends Model
{
    /** @use HasFactory<TaxPeriodFactory> */
    use HasFactory;
    use HasTeamScope;

    protected $fillable = [
        'team_id',
        'period_start',
        'period_end',
        'type',
        'status',
        'due_date',
        'submitted_at',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'due_date' => 'date',
            'submitted_at' => 'datetime',
            'type' => TaxPeriodType::class,
            'status' => TaxPeriodStatus::class,
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Team::class);
    }

    /**
     * @return HasOne<VATReturn, $this>
     */
    public function vatReturn(): HasOne
    {
        return $this->hasOne(VATReturn::class);
    }

    protected static function newFactory(): TaxPeriodFactory
    {
        return TaxPeriodFactory::new();
    }
}
