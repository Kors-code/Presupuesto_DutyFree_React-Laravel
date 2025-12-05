<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Commission extends Model {
    protected $fillable = ['sale_id','user_id','commission_amount','calculated_as','rule_id','is_provisional'];
    public function sale() { return $this->belongsTo(Sale::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function rule() { return $this->belongsTo(CategoryCommission::class, 'rule_id'); }
}
