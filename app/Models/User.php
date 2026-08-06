<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\TeamAccess\TeamAccess;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use HasRoles;
    use HasTeams;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'preferences',
        'completed_onboarding_at',
        'is_instance_operator',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            if (static::query()->count() === 1 && ! $user->is_instance_operator) {
                $user->forceFill(['is_instance_operator' => true])->saveQuietly();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'completed_onboarding_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
            'is_instance_operator' => 'boolean',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultPreferences(): array
    {
        return [
            'notify_invoice_overdue' => true,
            'notify_vat_due' => true,
            'notify_provisional_tax' => true,
            'notify_license_disk' => true,
            'date_format' => 'Y-m-d',
            'theme' => 'system',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mergedPreferences(): array
    {
        return array_merge(self::defaultPreferences(), $this->preferences ?? []);
    }

    /**
     * @return Collection<int, Team>
     */
    public function allTeams()
    {
        $this->loadMissing(['ownedTeams.media', 'teams.media']);

        return $this->ownedTeams->merge($this->teams)->sortBy('name');
    }

    public function canOnTeam(string $permission, ?Team $team = null): bool
    {
        return TeamAccess::allows($this, $team ?? $this->currentTeam, $permission);
    }
}
