<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateImportBatchesTable extends Migration
{
    public function up()
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('filename');
            $table->string('checksum')->unique();
            $table->date('import_date')->nullable();
            $table->integer('rows')->default(0);
            $table->string('status')->default('pending');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('import_batches');
    }
}
