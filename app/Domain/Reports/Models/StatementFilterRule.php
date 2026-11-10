<?php

namespace App\Domain\Reports\Models;

use App\Domain\Shared\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatementFilterRule extends Model
{
    use HasTeamScope;

    protected $fillable = [
        'team_id',
        'statement_filter_report_id',
        'alias',
        'match',
        'position',
    ];

    /**
     * @return BelongsTo<StatementFilterReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(StatementFilterReport::class, 'statement_filter_report_id');
    }
}
