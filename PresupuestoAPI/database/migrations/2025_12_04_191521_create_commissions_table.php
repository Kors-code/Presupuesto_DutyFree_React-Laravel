<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('commission_amount', 14, 2)->default(0);
            $table->string('calculated_as')->nullable();
            // rule_id y is_provisional se agregarán con migration posterior
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('commissions'); }
};
