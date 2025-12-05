<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
public function up()
{
    Schema::create('categories', function (Blueprint $table) {
        $table->id();
        $table->string('classification_code')->unique(); // 18, 11, 12...
        $table->string('name')->nullable();              // opcional si quieres un nombre público
        $table->string('description')->nullable();       // LICOR & WINE
        $table->timestamps();
    });
}

    public function down(): void { Schema::dropIfExists('categories'); }
};
