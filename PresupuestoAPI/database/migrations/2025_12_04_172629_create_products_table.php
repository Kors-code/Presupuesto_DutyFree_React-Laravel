<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('product_code')->nullable()->index();  // CODIGO
            $table->string('upc')->nullable()->index();          // UPC1
            $table->text('description')->nullable();
            $table->string('brand')->nullable();
            $table->string('classification')->nullable(); // CLASIFICACION
            $table->string('classification_desc')->nullable();
            $table->string('provider_code')->nullable();
            $table->string('provider_name')->nullable();
            $table->decimal('regular_price', 12, 2)->nullable();
            $table->decimal('cost_usd', 12, 2)->nullable();
            $table->decimal('avg_cost_usd', 12, 2)->nullable();
            $table->string('currency')->nullable();
            $table->string('type')->nullable();
            $table->timestamps();
            $table->unique(['product_code','upc']);
        });
    }
    public function down(): void { Schema::dropIfExists('products'); }
};
