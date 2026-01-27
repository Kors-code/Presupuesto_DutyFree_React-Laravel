<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetUserTurn extends Model
{
    protected $table = 'budget_user_turns';
    protected $guarded = [];
    public $timestamps = false; // si tu tabla no tiene timestamps
}
