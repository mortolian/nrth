<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Invoicing\Models\Payment;
use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use App\Models\User;
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
        'type',
        'status',
        'reference',
        'description',
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
            'transaction_date' => 'date',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
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
     * Files attached to this transaction (Spatie Media Library).
     *
     * @return Collection<int, Media>
     */
    public function attachments(): Collection
    {
        return $this->getMedia('attachments');
    }
}
