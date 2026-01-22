<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBudgetUserCategoryTotalsTable extends Migration
{
    public function up()
    {
        Schema::create('budget_user_category_totals', function (Blueprint $table) {
            $table->unsignedBigInteger('budget_id');
            $table->unsignedBigInteger('user_id');
            $table->string('category_group', 50);
            $table->decimal('sales_usd', 14, 2)->default('0.00');
            $table->decimal('sales_cop', 14, 2)->default('0.00');
            $table->decimal('commission_cop', 14, 2)->default('0.00');
            $table->decimal('applied_pct', 6, 2)->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->primary(['budget_id', 'user_id', 'category_group']);
            $table->index(['budget_id','user_id'], 'idx_budget_user');

            // foreign keys (require referenced tables to exist)
            $table->foreign('budget_id')->references('id')->on('budgets')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('budget_user_category_totals');
    }
}
