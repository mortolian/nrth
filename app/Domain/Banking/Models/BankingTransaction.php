<?php

namespace App\Domain\Banking\Models;

use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use Database\Factories\BankingTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected static function newFactory(): BankingTransactionFactory
    {
        return BankingTransactionFactory::new();
    }
}
