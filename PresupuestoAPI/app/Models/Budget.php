<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Budget extends Model
{
    protected $fillable = [
        'name',
        'target_amount',
        'min_pct_to_qualify',
        'start_date',
        'end_date'
    ];
}
