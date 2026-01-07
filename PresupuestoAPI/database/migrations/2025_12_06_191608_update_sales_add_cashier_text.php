<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up(): void
{
    Schema::table('sales', function (Blueprint $table) {
        $table->dropForeign(['cashier_id']);
        $table->dropColumn('cashier_id');

        // nuevo campo texto obligatorio
        $table->string('cashier')->default('');
    });
}

public function down(): void
{
    Schema::table('sales', function (Blueprint $table) {
        $table->dropColumn('cashier');
        $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
    });
}

};
