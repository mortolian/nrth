<?php

namespace App\Domain\Banking\Models;

use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use Database\Factories\BankingAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankingAccount extends Model
{
    /** @use HasFactory<BankingAccountFactory> */
    use HasFactory;

    use HasTeamScope;

    protected $table = 'banking_accounts';

    protected $fillable = [
        'team_id',
        'name',
        'bank_name',
        'account_number_last4',
        'currency',
        'type',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * @return HasMany<BankingStatementImport, $this>
     */
    public function imports(): HasMany
    {
        return $this->hasMany(BankingStatementImport::class, 'account_id');
    }

    /**
     * @return HasMany<BankingTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(BankingTransaction::class, 'account_id');
    }

    protected static function newFactory(): BankingAccountFactory
    {
        return BankingAccountFactory::new();
    }
}
