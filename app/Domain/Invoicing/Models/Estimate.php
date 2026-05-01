<?php

namespace App\Domain\Invoicing\Models;

use App\Domain\Invoicing\Enums\EstimateStatus;
use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Estimate extends Model implements HasMedia
{
    use HasTeamScope;
    use InteractsWithMedia;

    protected $table = 'estimates';

    protected $fillable = [
        'team_id',
        'client_id',
        'status',
        'number',
        'issue_date',
        'expiry_date',
        'subtotal_cents',
        'vat_amount_cents',
        'total_cents',
        'currency',
        'line_items',
        'notes',
        'terms',
        'sent_at',
        'accepted_at',
        'declined_at',
        'converted_invoice_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EstimateStatus::class,
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'line_items' => 'array',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
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
     * @return BelongsTo<Invoice, $this>
     */
    public function convertedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'converted_invoice_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('estimate-pdfs')->singleFile();
    }

    /**
     * @return Collection<int, Media>
     */
    public function pdfs(): Collection
    {
        return $this->getMedia('estimate-pdfs');
    }
}
