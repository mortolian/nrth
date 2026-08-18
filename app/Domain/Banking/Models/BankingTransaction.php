<?php

namespace App\Domain\Banking\Models;

use App\Domain\Banking\Enums\ReconciliationStatus;
use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use App\Models\User;
use Database\Factories\BankingTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankingTransaction extends Model
{
    /** @use HasFactory<BankingTransactionFactory> */
    use HasFactory;

    use HasTeamScope;

    protected $table = 'banking_transactions';

    protected $fillable = [
        'team_id',
        'account_id',
        'banking_statement_import_id',
        'transaction_date',
        'value_date',
        'description',
        'reference',
        'amount',
        'currency',
        'direction',
        'running_balance',
        'source_hash',
        'duplicate_key',
        'metadata',
        'reconciliation_status',
        'exclusion_note',
        'excluded_by',
        'excluded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'value_date' => 'date',
            'amount' => 'decimal:2',
            'running_balance' => 'decimal:2',
            'direction' => TransactionDirection::class,
            'metadata' => 'array',
            'reconciliation_status' => ReconciliationStatus::class,
            'excluded_at' => 'datetime',
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
     * @return BelongsTo<BankingAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BankingAccount::class, 'account_id');
    }

    /**
     * @return BelongsTo<BankingStatementImport, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(BankingStatementImport::class, 'banking_statement_import_id');
    }

    /**
     * @return HasMany<BankingTransactionAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(BankingTransactionAllocation::class, 'banking_transaction_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function excludedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excluded_by');
    }

    protected static function newFactory(): BankingTransactionFactory
    {
        return BankingTransactionFactory::new();
    }
}
