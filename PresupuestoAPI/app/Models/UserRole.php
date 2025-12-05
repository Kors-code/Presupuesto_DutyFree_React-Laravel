<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class UserRole extends Model {
    protected $fillable = ['user_id','role_id','start_date','end_date'];
    public function user() { return $this->belongsTo(User::class); }
    public function role() { return $this->belongsTo(Role::class); }
}
