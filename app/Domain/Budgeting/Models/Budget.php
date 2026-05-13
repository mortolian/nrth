<?php

namespace App\Domain\Budgeting\Models;

use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Budget extends Model
{
    use HasTeamScope;
    use SoftDeletes;

    protected $fillable = [
        'team_id',
        'name',
        'period_type',
        'start_date',
        'end_date',
        'currency',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
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
     * @return HasMany<BudgetCategory, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(BudgetCategory::class)->orderBy('sort_order');
    }
}
