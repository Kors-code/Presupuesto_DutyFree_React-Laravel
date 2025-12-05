<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Budget extends Model {
    protected $fillable = ['month','amount','total_turns','note'];
    public function personTurns() { return $this->hasMany(PersonTurn::class); }
}
