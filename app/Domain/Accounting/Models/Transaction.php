<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Banking\Models\BankingTransactionAllocation;
use App\Domain\Invoicing\Models\Payment;
use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use App\Models\User;
use App\Support\MediaDisks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Transaction extends Model implements HasMedia
{
    use HasTeamScope;
    use InteractsWithMedia;

    protected $fillable = [
        'team_id',
        'supplier_id',
        'type',
        'status',
        'reference',
        'description',
        'expense_meta',
        'transaction_date',
        'posted_at',
        'voided_at',
        'voided_reason',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'expense_meta' => 'array',
            'transaction_date' => 'date',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')->useDisk(MediaDisks::private());
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<JournalEntry, $this>
     */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<TaxLine, $this>
     */
    public function taxLines(): HasMany
    {
        return $this->hasMany(TaxLine::class);
    }

    /**
     * @return HasMany<BankingTransactionAllocation, $this>
     */
    public function bankingAllocations(): HasMany
    {
        return $this->hasMany(BankingTransactionAllocation::class);
    }

    /**
     * Files attached to this transaction (Spatie Media Library).
     *
     * @return Collection<int, Media>
     */
    public function attachments(): Collection
    {
        return $this->getMedia('attachments');
    }

    /**
     * User-facing reference for lists and statements.
     * Expenses store the receipt/invoice number in expense_meta; other types use the column.
     */
    public function displayReference(): ?string
    {
        if ($this->type === TransactionType::Expense) {
            $external = trim((string) (($this->expense_meta ?? [])['external_reference'] ?? ''));

            return $external !== '' ? $external : null;
        }

        $reference = trim((string) ($this->reference ?? ''));

        return $reference !== '' ? $reference : null;
    }

    /**
     * Counterparty label for lists. Prefers the live supplier name when linked.
     */
    public function displaySupplier(): ?string
    {
        if ($this->supplier !== null) {
            $name = trim((string) $this->supplier->name);

            return $name !== '' ? $name : null;
        }

        if ($this->type === TransactionType::Expense) {
            $fallback = trim((string) ($this->reference ?? ''));

            return $fallback !== '' ? $fallback : null;
        }

        return null;
    }
}
