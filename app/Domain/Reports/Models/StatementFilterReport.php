<?php

namespace App\Domain\Reports\Models;

use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatementFilterReport extends Model
{
    use HasTeamScope;

    protected $fillable = [
        'team_id',
        'name',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<StatementFilterRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(StatementFilterRule::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @param  list<array{alias: string, match: string}>  $rules
     */
    public function replaceRules(array $rules): void
    {
        $this->rules()->delete();

        foreach (array_values($rules) as $position => $rule) {
            $this->rules()->create([
                'team_id' => $this->team_id,
                'alias' => trim($rule['alias']),
                'match' => trim($rule['match']),
                'position' => $position,
            ]);
        }
    }
}
