<?php

namespace App\Imports;

use App\Models\User;
use App\Models\Budget;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;

class AssignTurnsByMonthImport implements ToCollection
{
    public array $errors = [];

    public function collection(Collection $rows)
    {
        $rows = $rows->skip(1); // quitar encabezados

        DB::transaction(function () use ($rows) {

            foreach ($rows as $index => $row) {

                $mes = trim($row[0] ?? '');
                $codigo = trim($row[1] ?? '');
                $turns = (int) ($row[2] ?? 0);



                if (!$mes || !$codigo || $turns < 0) {
                    $this->errors[] = "Fila ".($index+2)." inválida";
                    continue;
                }

                // 📅 Buscar presupuesto por mes
                try {
                    $startOfMonth = Carbon::createFromFormat('Y-m', $mes)->startOfMonth();
                    $endOfMonth   = Carbon::createFromFormat('Y-m', $mes)->endOfMonth();
                } catch (\Exception $e) {
                    $this->errors[] = "Fila ".($index+2).": mes inválido";
                    continue;
                }

                $budget = Budget::where('start_date', '<=', $endOfMonth)
                    ->where('end_date', '>=', $startOfMonth)
                    ->first();

                if (!$budget) {
                    $this->errors[] = "Fila ".($index+2).": no existe presupuesto para {$mes}";
                    continue;
                }

                // 👤 Buscar usuario por código
                $user = User::where('seller_code', $codigo)->first();

                if (!$user) {
                    $this->errors[] = "Fila ".($index+2).": vendedor {$codigo} no existe";
                    continue;
                }

                // 💾 Guardar turnos
                DB::table('budget_user_turns')->updateOrInsert(
                    [
                        'budget_id' => $budget->id,
                        'user_id' => $user->id
                    ],
                    [
                        'assigned_turns' => $turns,
                        'updated_at' => now()
                    ]
                );
            }
        });
    }
}
