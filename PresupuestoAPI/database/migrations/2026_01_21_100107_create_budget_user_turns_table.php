<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBudgetUserTurnsTable extends Migration
{
    public function up()
    {
        Schema::create('budget_user_turns', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('budget_id');
            $table->unsignedBigInteger('user_id');
            $table->integer('assigned_turns')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->unique(['budget_id','user_id'], 'uq_budget_user');
            $table->index('user_id', 'fk_but_user');

            $table->foreign('budget_id')->references('id')->on('budgets')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('budget_user_turns');
    }
}
