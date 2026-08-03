<?php

namespace App\Domain\Vehicles\Models;

use App\Domain\Shared\HasTeamScope;
use App\Domain\Vehicles\Enums\TripPurpose;
use App\Models\Team;
use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trip extends Model
{
    /** @use HasFactory<TripFactory> */
    use HasFactory;

    use HasTeamScope;

    protected $fillable = [
        'team_id',
        'vehicle_id',
        'trip_date',
        'started_at',
        'ended_at',
        'duration_seconds',
        'distance_km',
        'purpose',
        'start_odometer_km',
        'end_odometer_km',
        'from_location',
        'to_location',
        'start_latitude',
        'start_longitude',
        'end_latitude',
        'end_longitude',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trip_date' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'distance_km' => 'decimal:1',
            'start_odometer_km' => 'decimal:1',
            'end_odometer_km' => 'decimal:1',
            'start_latitude' => 'decimal:7',
            'start_longitude' => 'decimal:7',
            'end_latitude' => 'decimal:7',
            'end_longitude' => 'decimal:7',
            'purpose' => TripPurpose::class,
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

    protected static function newFactory(): TripFactory
    {
        return TripFactory::new();
    }
}
