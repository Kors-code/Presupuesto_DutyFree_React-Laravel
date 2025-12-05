<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Product extends Model {
    protected $fillable = [
        'product_code','upc','description','brand','classification',
        'classification_desc','provider_code','provider_name',
        'regular_price','cost_usd','currency','avg_cost_usd','type'
    ];
    public function sales() { return $this->hasMany(Sale::class); }
}
