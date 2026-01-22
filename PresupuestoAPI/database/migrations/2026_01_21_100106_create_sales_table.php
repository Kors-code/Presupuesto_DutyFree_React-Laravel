<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSalesTable extends Migration
{
    public function up()
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('sale_date')->nullable();
            $table->string('folio')->nullable();
            $table->string('pdv')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->decimal('quantity', 12, 2)->default('0.00');
            $table->decimal('amount', 14, 2)->default('0.00');
            $table->decimal('value_pesos', 14, 2)->nullable();
            $table->decimal('value_usd', 14, 2)->nullable();
            $table->decimal('cost', 14, 2)->nullable();
            $table->string('currency')->nullable();
            $table->decimal('exchange_rate', 10, 4)->nullable();
            $table->decimal('amount_cop', 14, 2)->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('seller_id');
            $table->timestamps();
            $table->string('cashier')->default('');
            $table->unsignedBigInteger('import_batch_id')->nullable();

            $table->foreign('import_batch_id')->references('id')->on('import_batches')->onDelete('set null');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
            $table->foreign('seller_id')->references('id')->on('users');

            $table->index(['sale_date','seller_id'], 'sales_sale_date_seller_id_index');
            $table->index('import_batch_id', 'idx_sales_import_batch_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sales');
    }
}
