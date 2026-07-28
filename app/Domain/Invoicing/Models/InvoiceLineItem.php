<?php

namespace App\Domain\Invoicing\Models;

use App\Domain\Accounting\Models\Account;
use Database\Factories\InvoiceLineItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLineItem extends Model
{
    /** @use HasFactory<InvoiceLineItemFactory> */
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'item_id',
        'income_account_id',
        'description',
        'quantity',
        'unit_price_cents',
        'vat_rate',
        'discount_type',
        'discount_percent',
        'discount_cents',
        'discount_amount_cents',
        'vat_amount_cents',
        'total_cents',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'vat_rate' => 'decimal:4',
            'discount_percent' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'income_account_id');
    }

    public function calculateVAT(): int
    {
        $lineSubtotal = (int) round((float) $this->quantity * (int) $this->unit_price_cents);

        return (int) round($lineSubtotal * (float) $this->vat_rate);
    }

    public function calculateTotal(): int
    {
        $lineSubtotal = (int) round((float) $this->quantity * (int) $this->unit_price_cents);

        return $lineSubtotal + (int) $this->calculateVAT();
    }

    protected static function newFactory(): InvoiceLineItemFactory
    {
        return InvoiceLineItemFactory::new();
    }
}
