<?php

namespace App\Domain\Banking\Models;

use App\Domain\Accounting\Models\Transaction;
use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankingTransactionAllocation extends Model
{
    use HasTeamScope;

    protected $table = 'banking_transaction_allocations';

    protected $fillable = [
        'team_id',
        'banking_transaction_id',
        'transaction_id',
        'amount_cents',
        'note',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
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
     * @return BelongsTo<BankingTransaction, $this>
     */
    public function bankingTransaction(): BelongsTo
    {
        return $this->belongsTo(BankingTransaction::class, 'banking_transaction_id');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
