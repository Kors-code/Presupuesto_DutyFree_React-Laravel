<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->date('sale_date')->nullable();
            $table->string('folio')->nullable();
            $table->string('pdv')->nullable();

            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->decimal('quantity', 12, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0); // total
            $table->decimal('value_pesos', 14, 2)->nullable();
            $table->decimal('value_usd', 14, 2)->nullable();
            $table->decimal('cost', 14, 2)->nullable();
            $table->string('currency')->nullable();
            $table->string('status')->nullable();

            $table->foreignId('seller_id')->constrained('users'); // obligatorio
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->index(['sale_date','seller_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('sales'); }
};
