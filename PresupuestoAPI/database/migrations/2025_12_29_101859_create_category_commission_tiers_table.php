<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCategoryCommissionTiersTable extends Migration
{
    public function up()
    {
        Schema::create('category_commission_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->nullable()->index();
            $table->string('label')->nullable();
            $table->decimal('min_pct', 8, 2)->nullable();
            $table->decimal('max_pct', 8, 2)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // foreign key optional (if categories table exists)
            if (Schema::hasTable('categories')) {
                $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
            }
        });
    }

    public function down()
    {
        Schema::dropIfExists('category_commission_tiers');
    }
}
