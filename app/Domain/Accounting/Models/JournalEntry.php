<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Casts\MoneyCast;
use App\Domain\Accounting\Enums\EntryType;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntry extends Model
{
    protected $fillable = [
        'transaction_id',
        'account_id',
        'type',
        'amount_cents',
        'currency',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EntryType::class,
            'amount_cents' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Monetary amount as {@see Money} (same underlying value as the cast on {@see $amount_cents}).
     */
    public function money(): Money
    {
        return Money::ofMinor(
            (int) $this->getRawOriginal('amount_cents'),
            (string) $this->getRawOriginal('currency') ?: 'ZAR'
        );
    }
}
