<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    use HasTeamScope;

    protected $fillable = [
        'team_id',
        'name',
        'contact_name',
        'email',
        'phone',
        'vat_number',
        'registration_number',
        'address',
        'notes',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'address' => 'array',
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
     * @return HasMany<Transaction, $this>
     */
    public function expenseTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class)->where('type', TransactionType::Expense->value);
    }

    protected static function newFactory(): SupplierFactory
    {
        return SupplierFactory::new();
    }
}
