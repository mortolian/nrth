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
        'status',
        'number',
        'reference',
        'issue_date',
        'due_date',
        'subtotal_cents',
        'vat_amount_cents',
        'total_cents',
        'amount_paid_cents',
        'currency',
        'notes',
        'footer',
        'sent_at',
        'viewed_at',
        'paid_at',
        'voided_at',
        'transaction_id',
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
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
            'subtotal_cents' => MoneyCast::class,
            'vat_amount_cents' => MoneyCast::class,
            'total_cents' => MoneyCast::class,
            'amount_paid_cents' => MoneyCast::class,
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
        if ($this->status === InvoiceStatus::Paid || $this->status === InvoiceStatus::Void) {
            return false;
        }

        $checkDate = $asOf ?? now();

        return $this->due_date->lessThan($checkDate);
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

    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }
}
