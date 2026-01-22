<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBudgetUserCommissionsTable extends Migration
{
    public function up()
    {
        Schema::create('budget_user_commissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('budget_id');
            $table->unsignedBigInteger('user_id');
            $table->double('total_sales_usd')->default(0);
            $table->double('total_sales_cop')->default(0);
            $table->double('total_commission_cop')->default(0);
            $table->double('total_commission_usd')->default(0);
            $table->boolean('is_provisional')->default(false);
            $table->json('details')->nullable();
            $table->timestamps();

            $table->unique(['budget_id','user_id'], 'budget_user_unique');
            $table->index('budget_id', 'idx_budget');
            $table->index('user_id', 'idx_user');

            $table->foreign('budget_id')->references('id')->on('budgets')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('budget_user_commissions');
    }
}
