<?php

namespace App\Domain\Vehicles\Models;

use App\Domain\Shared\HasTeamScope;
use App\Domain\Vehicles\Enums\TripImportStatus;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TripImport extends Model
{
    use HasTeamScope;

    protected $fillable = [
        'team_id',
        'vehicle_id',
        'original_filename',
        'parser',
        'status',
        'imported_rows',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TripImportStatus::class,
            'imported_rows' => 'integer',
            'metadata' => 'array',
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
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @return HasMany<Trip, $this>
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'trip_import_id');
    }
}
