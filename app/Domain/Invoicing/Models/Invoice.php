<?php

namespace App\Domain\Invoicing\Models;

use App\Domain\Accounting\Casts\MoneyCast;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use Brick\Money\Money;
use Carbon\Carbon;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Invoice extends Model implements HasMedia
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    use HasTeamScope;
    use InteractsWithMedia;

    protected $fillable = [
        'team_id',
        'client_id',
        'recurring_invoice_id',
        'status',
        'number',
        'reference',
        'issue_date',
        'due_date',
        'subtotal_cents',
        'vat_amount_cents',
        'total_cents',
        'amount_paid_cents',
        'discount_type',
        'discount_percent',
        'discount_cents',
        'discount_total_cents',
        'income_account_id',
        'accrual_transaction_id',
        'currency',
        'business_currency_code',
        'fx_rate_invoice_to_business',
        'fx_rate_date',
        'total_business_currency_cents',
        'notes',
        'footer',
        'sent_at',
        'viewed_at',
        'paid_at',
        'voided_at',
        'transaction_id',
        'public_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'fx_rate_date' => 'date',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
            'subtotal_cents' => MoneyCast::class,
            'vat_amount_cents' => MoneyCast::class,
            'total_cents' => MoneyCast::class,
            'amount_paid_cents' => MoneyCast::class,
            'discount_percent' => 'decimal:2',
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
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<RecurringInvoice, $this>
     */
    public function recurringInvoice(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class);
    }

    /**
     * @return HasMany<InvoiceLineItem, $this>
     */
    public function lineItems(): HasMany
    {
        return $this->hasMany(InvoiceLineItem::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('invoice-pdfs')->singleFile();
    }

    /**
     * @return Collection<int, Media>
     */
    public function pdfs(): Collection
    {
        return $this->getMedia('invoice-pdfs');
    }

    public function amountDue(): Money
    {
        return $this->total_cents->minus($this->amount_paid_cents);
    }

    public function isOverdue(?Carbon $asOf = null): bool
    {
        if (! $this->status->isOpen()) {
            return false;
        }

        $checkDate = $asOf ?? now();

        return $this->due_date !== null
            && $this->due_date->lessThan($checkDate->copy()->startOfDay())
            && $this->amountDue()->isPositive();
    }

    public function vatRate(): float
    {
        $subtotal = (int) $this->subtotal_cents->getMinorAmount()->toInt();
        if ($subtotal === 0) {
            return 0.0;
        }

        $vat = (int) $this->vat_amount_cents->getMinorAmount()->toInt();

        return round($vat / $subtotal, 4);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeIssued($query)
    {
        return $query->whereIn('status', InvoiceStatus::issuedValues());
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', InvoiceStatus::openValues());
    }

    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }
}
