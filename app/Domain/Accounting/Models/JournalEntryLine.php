<?php

namespace App\Domain\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Placeholder for the journal engine (phase 2.2). Links journal lines to accounts.
 */
class JournalEntryLine extends Model
{
    protected $table = 'journal_entry_lines';

    protected $fillable = [
        'account_id',
    ];

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
