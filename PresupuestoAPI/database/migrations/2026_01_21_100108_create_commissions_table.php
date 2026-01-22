<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCommissionsTable extends Migration
{
    public function up()
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('budget_id')->nullable();
            $table->decimal('commission_amount', 14, 2)->default('0.00');
            $table->boolean('is_provisional')->default(true);
            $table->string('calculated_as')->nullable();
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->timestamps();
            $table->bigInteger('applied_commission_pct')->nullable();

            $table->index('sale_id', 'commissions_sale_id_foreign');
            $table->index('user_id', 'commissions_user_id_foreign');
            $table->index('rule_id', 'commissions_rule_id_foreign');
            $table->index('budget_id', 'idx_commissions_budget');

            $table->foreign('rule_id')->references('id')->on('category_commissions')->onDelete('set null');
            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('budget_id')->references('id')->on('budgets')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('commissions');
    }
}
