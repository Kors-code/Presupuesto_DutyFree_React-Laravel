<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductsTable extends Migration
{
    public function up()
    {
        Schema::create('products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('product_code')->nullable();
            $table->string('upc')->nullable();
            $table->text('description')->nullable();
            $table->string('brand')->nullable();
            $table->string('classification')->nullable();
            $table->string('classification_desc')->nullable();
            $table->string('provider_code')->nullable();
            $table->string('provider_name')->nullable();
            $table->decimal('regular_price', 12, 2)->nullable();
            $table->decimal('cost_usd', 12, 2)->nullable();
            $table->decimal('avg_cost_usd', 12, 2)->nullable();
            $table->string('currency')->nullable();
            $table->string('type')->nullable();
            $table->timestamps();

            $table->unique(['product_code', 'upc'], 'products_product_code_upc_unique');
            $table->index('product_code', 'products_product_code_index');
            $table->index('upc', 'products_upc_index');
        });
    }

    public function down()
    {
        Schema::dropIfExists('products');
    }
}
