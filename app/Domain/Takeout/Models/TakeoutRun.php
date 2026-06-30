<?php

namespace App\Domain\Takeout\Models;

use App\Domain\Shared\HasTeamScope;
use App\Domain\Takeout\Enums\TakeoutRunStatus;
use App\Models\Team;
use App\Models\User;
use Database\Factories\TakeoutRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TakeoutRun extends Model
{
    /** @use HasFactory<TakeoutRunFactory> */
    use HasFactory;
    use HasTeamScope;

    protected $fillable = [
        'team_id',
        'requested_by',
        'from_date',
        'to_date',
        'status',
        'download_token',
        'storage_path',
        'file_size_bytes',
        'manifest',
        'error_message',
        'expires_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'status' => TakeoutRunStatus::class,
            'manifest' => 'array',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public static function generateDownloadToken(): string
    {
        return Str::random(48);
    }

    public function isDownloadable(): bool
    {
        return $this->status === TakeoutRunStatus::Ready
            && $this->storage_path !== null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
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
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    protected static function newFactory(): TakeoutRunFactory
    {
        return TakeoutRunFactory::new();
    }

    public function getRouteKeyName(): string
    {
        return 'download_token';
    }
}
