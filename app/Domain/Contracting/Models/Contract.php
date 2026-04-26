<?php

namespace App\Domain\Contracting\Models;

use App\Domain\Invoicing\Models\Client;
use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Contract extends Model implements HasMedia
{
    use HasTeamScope;
    use InteractsWithMedia;

    protected $fillable = [
        'team_id',
        'client_id',
        'title',
        'status',
        'billing_type',
        'start_date',
        'end_date',
        'contract_value_cents',
        'hourly_rate_cents',
        'monthly_amount_cents',
        'payment_terms',
        'scope_of_work',
        'next_invoice_due_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'next_invoice_due_date' => 'date',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('signed-contract')->singleFile();
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
}
