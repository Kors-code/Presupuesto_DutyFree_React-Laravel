<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    protected $fillable = ['name','email'];

    public function salesAsSeller(): HasMany { return $this->hasMany(Sale::class, 'seller_id'); }
    public function salesAsCashier(): HasMany { return $this->hasMany(Sale::class, 'cashier_id'); }
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
