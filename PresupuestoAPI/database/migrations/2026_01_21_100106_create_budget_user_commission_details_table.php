<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBudgetUserCommissionDetailsTable extends Migration
{
    public function up()
    {
        Schema::create('budget_user_commission_details', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('budget_id');
            $table->unsignedBigInteger('user_id');
            $table->string('category_group', 100);
            $table->decimal('sales_usd', 14, 2)->default('0.00');
            $table->decimal('sales_cop', 14, 2)->default('0.00');
            $table->decimal('commission_cop', 14, 2)->default('0.00');
            $table->decimal('applied_pct', 6, 2)->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['budget_id','user_id','category_group'], 'ux_budget_user_group');
            $table->index(['budget_id','user_id'], 'idx_bud_user');

            $table->foreign('budget_id')->references('id')->on('budgets')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('budget_user_commission_details');
    }
}
