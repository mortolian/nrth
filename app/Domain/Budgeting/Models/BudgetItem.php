<?php

namespace App\Domain\Budgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetItem extends Model
{
    protected $fillable = [
        'budget_category_id',
        'label',
        'monthly_amount_cents',
        'currency',
        'monthly_budget_currency_cents',
        'fx_budget_per_line_major',
        'sort_order',
    ];

    /**
     * @return BelongsTo<BudgetCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class, 'budget_category_id');
    }
}
