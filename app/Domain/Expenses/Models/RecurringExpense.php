<?php

namespace App\Domain\Expenses\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Expenses\Enums\RecurringExpenseStatus;
use App\Domain\Invoicing\Enums\RecurringFrequency;
use App\Domain\Invoicing\Enums\RecurringLimitType;
use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use Database\Factories\RecurringExpenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringExpense extends Model
{
    /** @use HasFactory<RecurringExpenseFactory> */
    use HasFactory;

    use HasTeamScope;

    /**
     * Route binding must see team-scoped rows for the authenticated user.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $field ??= $this->getRouteKeyName();

        return static::queryWithoutTeamScope()
            ->where($field, $value)
            ->when(
                auth()->user()?->current_team_id,
                fn ($q, $teamId) => $q->where('team_id', $teamId),
            )
            ->first();
    }

    protected $fillable = [
        'team_id',
        'supplier_id',
        'supplier_name',
        'category_account_id',
        'paid_from_banking_account_id',
        'status',
        'frequency',
        'generate_on_weekday',
        'generate_on_day',
        'generate_on_last_day',
        'generate_on_month',
        'limit_type',
        'limit_count',
        'limit_end_date',
        'generated_count',
        'next_run_date',
        'last_generated_at',
        'period_offset_months',
        'description',
        'notes',
        'reference',
        'amount_excl_vat_cents',
        'vat_rate',
        'vat_amount_cents',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RecurringExpenseStatus::class,
            'frequency' => RecurringFrequency::class,
            'limit_type' => RecurringLimitType::class,
            'generate_on_last_day' => 'boolean',
            'next_run_date' => 'date',
            'limit_end_date' => 'date',
            'last_generated_at' => 'datetime',
            'generated_count' => 'integer',
            'period_offset_months' => 'integer',
            'generate_on_weekday' => 'integer',
            'generate_on_day' => 'integer',
            'generate_on_month' => 'integer',
            'amount_excl_vat_cents' => 'integer',
            'vat_amount_cents' => 'integer',
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
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function categoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'category_account_id');
    }

    /**
     * @return BelongsTo<BankingAccount, $this>
     */
    public function paidFromBankingAccount(): BelongsTo
    {
        return $this->belongsTo(BankingAccount::class, 'paid_from_banking_account_id');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Transaction::class, 'recurring_expense_id');
    }

    public function displaySupplier(): string
    {
        return $this->supplier?->name
            ?: (string) ($this->supplier_name ?: 'Unknown supplier');
    }

    protected static function newFactory(): RecurringExpenseFactory
    {
        return RecurringExpenseFactory::new();
    }
}
