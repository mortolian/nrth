<?php

namespace App\Domain\Invoicing\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceNumberSequence extends Model
{
    protected $fillable = [
        'team_id',
        'year',
        'next_number',
    ];
}
