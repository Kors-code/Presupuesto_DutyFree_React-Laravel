<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('commissions', function (Blueprint $table) {
            if (!Schema::hasColumn('commissions','rule_id')) {
                $table->foreignId('rule_id')->nullable()->after('calculated_as')->constrained('category_commissions')->nullOnDelete();
            }
            if (!Schema::hasColumn('commissions','is_provisional')) {
                $table->boolean('is_provisional')->default(true)->after('commission_amount');
            }
        });
    }
    public function down(): void {
        Schema::table('commissions', function (Blueprint $table) {
            if (Schema::hasColumn('commissions','rule_id')) $table->dropConstrainedForeignId('rule_id');
            if (Schema::hasColumn('commissions','is_provisional')) $table->dropColumn('is_provisional');
        });
    }
};
