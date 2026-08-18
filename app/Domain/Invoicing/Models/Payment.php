<?php

namespace App\Domain\Invoicing\Models;

use App\Domain\Accounting\Casts\MoneyCast;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use App\Support\MediaDisks;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Payment extends Model implements HasMedia
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    use HasTeamScope;
    use InteractsWithMedia;

    protected $fillable = [
        'team_id',
        'invoice_id',
        'amount_cents',
        'currency',
        'bank_amount_business_cents',
        'payment_date',
        'method',
        'reference',
        'notes',
        'transaction_id',
        'banking_account_id',
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

    /**
     * @return BelongsTo<BankingAccount, $this>
     */
    public function bankingAccount(): BelongsTo
    {
        return $this->belongsTo(BankingAccount::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('payment-receipts')->useDisk(MediaDisks::private())->singleFile();
    }

    /**
     * @return Collection<int, Media>
     */
    public function receipts(): Collection
    {
        return $this->getMedia('payment-receipts');
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }
}
