<?php

namespace App\Domain\Invoicing\Models;

use App\Domain\Invoicing\Enums\OnlinePaymentSessionStatus;
use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceOnlinePaymentSession extends Model
{
    use HasTeamScope;

    protected $fillable = [
        'team_id',
        'invoice_id',
        'provider',
        'status',
        'amount_cents',
        'currency',
        'provider_checkout_id',
        'payment_id',
        'completed_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OnlinePaymentSessionStatus::class,
            'completed_at' => 'datetime',
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
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
