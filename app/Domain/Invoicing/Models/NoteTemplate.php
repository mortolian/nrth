<?php

namespace App\Domain\Invoicing\Models;

use App\Domain\Shared\HasTeamScope;
use App\Models\Team;
use Database\Factories\NoteTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class NoteTemplate extends Model
{
    /** @use HasFactory<NoteTemplateFactory> */
    use HasFactory;

    use HasTeamScope;

    protected $fillable = [
        'team_id',
        'name',
        'body',
        'target',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
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
     * @return BelongsToMany<Client, $this>
     */
    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_note_template')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    protected static function newFactory(): NoteTemplateFactory
    {
        return NoteTemplateFactory::new();
    }
}
