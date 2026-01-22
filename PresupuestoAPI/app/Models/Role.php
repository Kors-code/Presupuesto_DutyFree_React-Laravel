<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Role extends Model { 
    public function userBudgets()
{
    return $this->hasMany(UserRoleBudget::class, 'role_id');
}
}
