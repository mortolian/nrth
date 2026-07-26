<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstanceSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
