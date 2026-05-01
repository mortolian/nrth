<?php

namespace App\Domain\Invoicing\Models;

use App\Domain\Accounting\Casts\MoneyCast;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    use HasTeamScope;

    protected $fillable = [
        'team_id',
        'invoice_id',
        'amount_cents',
        'currency',
        'bank_amount_company_cents',
        'payment_date',
        'method',
        'reference',
        'notes',
        'transaction_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => MoneyCast::class,
            'method' => PaymentMethod::class,
            'payment_date' => 'date',
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
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }
}
