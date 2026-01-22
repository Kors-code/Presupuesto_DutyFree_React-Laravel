<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class CreateDefaultUserSeeder extends Seeder
{
    public function run()
    {
        $now = Carbon::now();

        $user = [
            'id' => 40,
            'name' => 'Usuario Predeterminado',
            'email' => 'default.user@example.com',
            'created_at' => $now,
            'updated_at' => $now,
            'codigo_vendedor' => null,
        ];

        // Si tu tabla users requiere password, descomenta y añade el hash
        // $user['password'] = Hash::make('changeme');

        DB::table('users')->updateOrInsert(['id' => 40], $user);
    }
}
