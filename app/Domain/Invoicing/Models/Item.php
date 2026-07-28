<?php

namespace App\Domain\Invoicing\Models;

use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    use HasTeamScope;

    protected $fillable = [
        'team_id',
        'name',
        'description',
        'unit',
        'unit_price_cents',
        'default_vat_rate',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_cents' => 'integer',
            'default_vat_rate' => 'decimal:4',
            'is_active' => 'boolean',
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
     * @return HasMany<InvoiceLineItem, $this>
     */
    public function invoiceLineItems(): HasMany
    {
        return $this->hasMany(InvoiceLineItem::class);
    }

    protected static function newFactory(): ItemFactory
    {
        return ItemFactory::new();
    }
}
