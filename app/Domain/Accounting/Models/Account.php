<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Exceptions\SystemAccountProtectedException;
use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    use HasTeamScope;

    protected $fillable = [
        'team_id',
        'parent_id',
        'code',
        'name',
        'description',
        'type',
        'is_system',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'is_system' => 'boolean',
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
     * @return BelongsTo<Account, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<JournalEntryLine, $this>
     */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'account_id');
    }

    protected static function newFactory(): AccountFactory
    {
        return AccountFactory::new();
    }

    protected static function booted(): void
    {
        static::deleting(function (Account $account): void {
            if ($account->is_system) {
                throw SystemAccountProtectedException::cannotDelete();
            }
        });

        static::updating(function (Account $account): void {
            if (! $account->is_system) {
                return;
            }

            if ($account->isDirty('code') || $account->isDirty('name')) {
                throw SystemAccountProtectedException::cannotRename();
            }

            if ($account->isDirty('is_active') && ! $account->is_active) {
                throw SystemAccountProtectedException::cannotDeactivate();
            }
        });
    }
}
