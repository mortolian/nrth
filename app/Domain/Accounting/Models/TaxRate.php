<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\HasTeamScope;
use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    use HasTeamScope;

    protected $fillable = [
        'team_id',
        'name',
        'code',
        'rate_percent',
        'is_exempt',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_percent' => 'decimal:2',
            'is_exempt' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
