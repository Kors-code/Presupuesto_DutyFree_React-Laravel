<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAssignedTurnsAndParticipationToUsersAndCategories extends Migration
{
    public function up()
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'assigned_turns')) {
            Schema::table('users', function (Blueprint $table) {
                $table->integer('assigned_turns')->default(0)->after('remember_token');
            });
        }

        if (Schema::hasTable('categories') && !Schema::hasColumn('categories', 'participation_pct')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->decimal('participation_pct', 5, 2)->nullable()->after('description');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'assigned_turns')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('assigned_turns');
            });
        }

        if (Schema::hasTable('categories') && Schema::hasColumn('categories', 'participation_pct')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('participation_pct');
            });
        }
    }
}
