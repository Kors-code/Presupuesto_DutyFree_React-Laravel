<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('email')->unique();
            // Si usas autenticación estándar, puedes descomentar password/remember/email_verified_at
            // $table->string('password')->nullable();
            // $table->timestamp('email_verified_at')->nullable();
            // $table->rememberToken();
            $table->timestamps();
            $table->bigInteger('codigo_vendedor')->nullable()->unique('uq_users_codigo_vendedor');
        });
    }

    public function down()
    {
        Schema::dropIfExists('users');
    }
}
