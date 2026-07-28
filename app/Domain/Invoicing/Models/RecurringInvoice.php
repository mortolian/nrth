<?php

namespace App\Domain\Invoicing\Models;

use App\Domain\Invoicing\Enums\RecurringDueDateRule;
use App\Domain\Invoicing\Enums\RecurringFrequency;
use App\Domain\Invoicing\Enums\RecurringInvoiceStatus;
use App\Domain\Invoicing\Enums\RecurringLimitType;
use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use Database\Factories\RecurringInvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringInvoice extends Model
{
    /** @use HasFactory<RecurringInvoiceFactory> */
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
        'client_id',
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
        'auto_send',
        'period_offset_months',
        'due_date_rule',
        'due_days',
        'due_on_day',
        'currency',
        'reference',
        'notes',
        'footer',
        'line_items',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RecurringInvoiceStatus::class,
            'frequency' => RecurringFrequency::class,
            'limit_type' => RecurringLimitType::class,
            'due_date_rule' => RecurringDueDateRule::class,
            'generate_on_last_day' => 'boolean',
            'auto_send' => 'boolean',
            'next_run_date' => 'date',
            'limit_end_date' => 'date',
            'last_generated_at' => 'datetime',
            'line_items' => 'array',
            'generated_count' => 'integer',
            'period_offset_months' => 'integer',
            'due_days' => 'integer',
            'due_on_day' => 'integer',
            'generate_on_weekday' => 'integer',
            'generate_on_day' => 'integer',
            'generate_on_month' => 'integer',
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
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    protected static function newFactory(): RecurringInvoiceFactory
    {
        return RecurringInvoiceFactory::new();
    }
}
