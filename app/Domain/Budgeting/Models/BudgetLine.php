<?php

namespace App\Domain\Budgeting\Models;

use App\Domain\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLine extends Model
{
    protected $fillable = [
        'budget_id',
        'account_id',
        'monthly_amount_cents',
        'annual_total_cents',
    ];

    /**
     * @return BelongsTo<Budget, $this>
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
