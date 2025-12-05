<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class CategoryCommission extends Model {
    protected $fillable = ['category_id','role_id','commission_percentage','min_pct_to_qualify'];
    public function category() { return $this->belongsTo(Category::class); }
    public function role() { return $this->belongsTo(Role::class); }
}
