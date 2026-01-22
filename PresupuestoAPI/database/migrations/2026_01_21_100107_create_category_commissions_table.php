<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCategoryCommissionsTable extends Migration
{
    public function up()
    {
        Schema::create('category_commissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('role_id');
            $table->decimal('commission_percentage', 6, 2)->default('0.00');
            $table->decimal('commission_percentage100', 6, 2)->default('0.00');
            $table->decimal('commission_percentage120', 6, 2)->default('0.00');
            $table->decimal('min_pct_to_qualify', 6, 2)->default('80.00');
            $table->timestamps();

            $table->unique(['category_id','role_id'], 'category_commissions_category_id_role_id_unique');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('category_commissions');
    }
}
