<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('category_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->decimal('commission_percentage', 6, 2)->default(0.00);
            $table->decimal('min_pct_to_qualify', 6, 2)->default(80.00);
            $table->timestamps();
            $table->unique(['category_id','role_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('category_commissions'); }
};
