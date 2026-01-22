<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTrmsTable extends Migration
{
    public function up()
    {
        Schema::create('trms', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('date')->unique('uk_trms_date');
            $table->decimal('value', 12, 4);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down()
    {
        Schema::dropIfExists('trms');
    }
}
