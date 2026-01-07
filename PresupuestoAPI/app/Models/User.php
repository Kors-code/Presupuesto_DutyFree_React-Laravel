<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    protected $fillable = ['name','email','role'];

    // ventas donde es seller (seller_id FK)
    public function salesAsSeller(): HasMany
    {
        return $this->hasMany(Sale::class, 'seller_id', 'id');
    }

    public function userRoles()
{
    return $this->hasMany(\App\Models\UserRole::class);
}

    // ventas donde aparece como cajero (sales.cashier es texto con el nombre)
    public function salesAsCashier(): HasMany
    {
        // foreign key in sales = 'cashier' (text), local key in users = 'name'
        return $this->hasMany(Sale::class, 'cashier', 'name');
    }

    public function roles() { return $this->hasMany(UserRole::class, 'user_id'); }

    public function roleAtDate($date = null) {
        $date = $date ?? now()->toDateString();
        return $this->roles()
            ->where('start_date','<=',$date)
            ->where(function($q) use ($date) { $q->where('end_date','>=',$date)->orWhereNull('end_date'); })
            ->with('role')
            ->first();
    }
}
