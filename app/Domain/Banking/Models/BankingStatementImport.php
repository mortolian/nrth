<?php

namespace App\Domain\Banking\Models;

use App\Domain\Banking\Enums\ImportStatus;
use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use Database\Factories\BankingStatementImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankingStatementImport extends Model
{
    /** @use HasFactory<BankingStatementImportFactory> */
    use HasFactory;

    use HasTeamScope;

    protected $table = 'banking_statement_imports';

    protected $fillable = [
        'team_id',
        'account_id',
        'original_filename',
        'stored_path',
        'file_type',
        'mime_type',
        'file_hash',
        'status',
        'total_rows',
        'imported_rows',
        'duplicate_rows',
        'failed_rows',
        'metadata',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'metadata' => 'array',
            'total_rows' => 'integer',
            'imported_rows' => 'integer',
            'duplicate_rows' => 'integer',
            'failed_rows' => 'integer',
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
     * @return BelongsTo<BankingAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BankingAccount::class, 'account_id');
    }

    /**
     * @return HasMany<BankingTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(BankingTransaction::class, 'banking_statement_import_id');
    }

    protected static function newFactory(): BankingStatementImportFactory
    {
        return BankingStatementImportFactory::new();
    }
}
