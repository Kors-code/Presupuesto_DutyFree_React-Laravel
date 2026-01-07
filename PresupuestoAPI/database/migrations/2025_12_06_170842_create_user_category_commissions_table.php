<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up()
{
    Schema::create('user_category_commissions', function (Blueprint $table) {
        $table->id();

        $table->foreignId('user_id')->constrained()->onDelete('cascade');
        $table->foreignId('category_id')->constrained()->onDelete('cascade');

        $table->decimal('commission_percentage', 5, 2)->default(0);
        $table->boolean('active')->default(true);

        $table->timestamps();
    });
}

public function down()
{
    Schema::dropIfExists('user_category_commissions');
}

};
