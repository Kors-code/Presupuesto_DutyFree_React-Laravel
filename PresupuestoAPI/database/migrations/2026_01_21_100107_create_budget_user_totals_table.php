<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBudgetUserTotalsTable extends Migration
{
    public function up()
    {
        Schema::create('budget_user_totals', function (Blueprint $table) {
            $table->unsignedBigInteger('budget_id');
            $table->unsignedBigInteger('user_id');
            $table->decimal('total_sales_usd', 14, 2)->default('0.00');
            $table->decimal('total_sales_cop', 14, 2)->default('0.00');
            $table->decimal('total_commission_cop', 14, 2)->default('0.00');
            $table->date('updated_at')->nullable();
            $table->date('created_at')->nullable();

            $table->primary(['budget_id','user_id']);

            $table->foreign('budget_id')->references('id')->on('budgets')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('budget_user_totals');
    }
}
