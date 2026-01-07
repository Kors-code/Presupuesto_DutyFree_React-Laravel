<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CategoryCommission extends Model
{
    use HasFactory;

protected $fillable = [
    'category_id',
    'role_id',
    'commission_percentage',
    'commission_percentage100',
    'commission_percentage120',
    'min_pct_to_qualify',
];


    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
