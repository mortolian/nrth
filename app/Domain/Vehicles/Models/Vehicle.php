<?php

namespace App\Domain\Vehicles\Models;

use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory;

    use HasTeamScope;

    protected $fillable = [
        'team_id',
        'name',
        'make',
        'model',
        'year',
        'registration_number',
        'vin',
        'license_disk_expires_on',
        'license_disk_reminder_sent_for',
        'starting_odometer_km',
        'notes',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'license_disk_expires_on' => 'date',
            'license_disk_reminder_sent_for' => 'date',
            'starting_odometer_km' => 'decimal:1',
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
     * @return HasMany<Trip, $this>
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    protected static function newFactory(): VehicleFactory
    {
        return VehicleFactory::new();
    }
}
