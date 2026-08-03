<?php

namespace App\Domain\Backup\Models;

use App\Domain\Backup\Enums\InstanceBackupRunStatus;
use App\Models\User;
use Database\Factories\InstanceBackupRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstanceBackupRun extends Model
{
    /** @use HasFactory<InstanceBackupRunFactory> */
    use HasFactory;

    protected $fillable = [
        'requested_by',
        'status',
        'types',
        'filename',
        'disk',
        'storage_path',
        'file_size_bytes',
        'error_message',
        'mirror_warning',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InstanceBackupRunStatus::class,
            'types' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function isDownloadable(): bool
    {
        return $this->status === InstanceBackupRunStatus::Ready
            && filled($this->filename)
            && filled($this->storage_path);
    }

    /**
     * @return list<string>
     */
    public function typeList(): array
    {
        $types = $this->types;

        if (! is_array($types)) {
            return [];
        }

        return array_values(array_filter(
            $types,
            static fn (mixed $type): bool => is_string($type) && $type !== '',
        ));
    }

    public function hasType(string $type): bool
    {
        return in_array($type, $this->typeList(), true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    protected static function newFactory(): InstanceBackupRunFactory
    {
        return InstanceBackupRunFactory::new();
    }
}
