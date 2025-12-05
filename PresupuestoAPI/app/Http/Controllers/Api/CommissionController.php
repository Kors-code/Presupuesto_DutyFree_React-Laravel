<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
    public function index(Request $request)
    {
        $query = Commission::with(['sale', 'user'])
            ->orderBy('id', 'desc');

        if ($request->has('from')) {
            $query->whereHas('sale', fn($q) =>
                $q->whereDate('sale_date', '>=', $request->from)
            );
        }

        if ($request->has('to')) {
            $query->whereHas('sale', fn($q) =>
                $q->whereDate('sale_date', '<=', $request->to)
            );
        }

        if ($request->pdv) {
            $query->whereHas('sale', fn($q) =>
                $q->where('pdv', $request->pdv)
            );
        }

        return response()->json([
            'data' => $query->paginate(50)
        ]);
    }

    public function byUser($userId)
    {
        $items = Commission::with(['sale'])
            ->where('user_id', $userId)
            ->orderBy('id','desc')
            ->get();

        return response()->json([
            'user_id' => $userId,
            'total' => $items->sum('commission_amount'),
            'items' => $items
        ]);
    }
}
