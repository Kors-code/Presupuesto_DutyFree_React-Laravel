<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ImportBatch extends Model {
    protected $fillable = ['filename','checksum','import_date','rows','status','note'];
    public function sales() { return $this->hasMany(Sale::class); }
}
