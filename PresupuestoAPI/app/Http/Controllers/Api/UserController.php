<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserRole;
use Carbon\Carbon;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Listar usuarios con su rol activo
     */
public function index()
{
    $users = User::with([
        'userRoles' => function ($q) {
            $q->whereNull('end_date')
              ->with('role');
        }
    ])->get();

    return response()->json($users);
}


    /**
     * Asignar o cambiar rol a un usuario
     */
    public function assignRole(Request $request, $userId)
    {
        $request->validate([
            'role_id' => 'required|exists:roles,id'
        ]);

        // Cerrar rol anterior
        UserRole::where('user_id', $userId)
            ->whereNull('end_date')
            ->update([
                'end_date' => Carbon::now()
            ]);

        // Crear nuevo rol
        UserRole::create([
            'user_id' => $userId,
            'role_id' => $request->role_id,
            'start_date' => Carbon::now(),
            'end_date' => null
        ]);

        return response()->json([
            'message' => 'Rol asignado correctamente'
        ]);
    }
}
