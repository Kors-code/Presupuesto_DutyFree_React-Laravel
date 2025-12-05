<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Budget;

class BudgetController extends Controller
{
    public function index() {
        return response()->json(Budget::orderBy('month','desc')->get());
    }

    public function store(Request $request) {
        $data = $request->validate([
            'month' => 'required|string',
            'amount' => 'required|numeric',
            'total_turns' => 'required|integer|min:0',
            'note' => 'nullable|string'
        ]);
        $b = Budget::create($data);
        return response()->json($b, 201);
    }

    public function show($id) {
        return response()->json(Budget::with(['personTurns.user','categoryTargets.category'])->findOrFail($id));
    }

    public function update(Request $request, $id) {
        $b = Budget::findOrFail($id);
        $data = $request->validate([
            'amount' => 'sometimes|numeric',
            'total_turns' => 'sometimes|integer|min:0',
            'note' => 'nullable|string'
        ]);
        $b->update($data);
        return response()->json($b);
    }

    public function destroy($id) {
        Budget::destroy($id);
        return response()->json(['message'=>'El presupuesto fue eliminado']);
    }
}
