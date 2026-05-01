<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamBankAccount extends Model
{
    protected $fillable = [
        'team_id',
        'sort_order',
        'title',
        'bank_name',
        'bank_account_holder',
        'bank_account_number',
        'bank_branch_code',
        'bank_account_type',
        'show_on_invoice',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'show_on_invoice' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
