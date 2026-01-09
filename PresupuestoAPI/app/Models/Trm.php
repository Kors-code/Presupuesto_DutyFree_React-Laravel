<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trm extends Model
{
    protected $fillable = [
        'date',
        'value',
    ];

    protected $casts = [
        'date' => 'date',
        'value' => 'float',
    ];
}
