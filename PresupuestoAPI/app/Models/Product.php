<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Product extends Model {
   protected $fillable = [
    'product_code',
    'upc',
    'description',
    'classification',
    'classification_desc',
    'brand',
    'currency',
    'provider_code',
    'provider_name',
    'regular_price',
    'avg_cost_usd',
    'cost_usd',
];

    public function sales() { return $this->hasMany(Sale::class); }
}
