<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCategoryTierCommissionsTable extends Migration
{
    public function up()
    {
        Schema::create('category_tier_commissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tier_id')->index();
            $table->unsignedBigInteger('role_id')->index();
            $table->decimal('commission_percentage', 8, 3)->nullable(); // allows precision like 0.5
            $table->timestamps();

            $table->foreign('tier_id')->references('id')->on('category_commission_tiers')->onDelete('cascade');
            if (Schema::hasTable('roles')) {
                $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            }
        });
    }

    public function down()
    {
        Schema::dropIfExists('category_tier_commissions');
    }
}
