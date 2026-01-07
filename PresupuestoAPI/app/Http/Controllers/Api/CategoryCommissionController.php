<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Category;
use App\Models\CategoryCommission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CategoryCommissionController extends Controller
{
    // List categories with commission (optionally filter by role_id)
    public function index(Request $request)
    {
        $roleId = $request->query('role_id');

        $categories = Category::select('id','classification_code as code','name','description')
            ->orderBy('name')
            ->get();

        // load commissions for those categories for role (if provided) in one query
        $commissions = [];
        if ($roleId) {
            $rows = CategoryCommission::whereIn('category_id',$categories->pluck('id')->toArray())
                ->where('role_id', $roleId)->get();
            $commissions = $rows->keyBy('category_id');
        } else {
            // optionally load default role? for now load no commissions
        }

        $payload = $categories->map(function($c) use ($commissions) {
            $r = $commissions[$c->id] ?? null;
           return [
                'category_id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'description' => $c->description,
                'commission_id' => $r ? $r->id : null,
                'commission_percentage' => $r ? (float)$r->commission_percentage : null,
                'commission_percentage100' => $r ? (float)$r->commission_percentage100 : null,
                'commission_percentage120' => $r ? (float)$r->commission_percentage120 : null,
                'min_pct_to_qualify' => $r ? (float)$r->min_pct_to_qualify : null,
            ];

        });

        return response()->json(['categories' => $payload]);
    }

    // Upsert a commission for category + role
    public function upsert(Request $request)
    {
        $data = $request->validate([
        'category_id' => ['required','integer','exists:categories,id'],
        'role_id' => ['required','integer','exists:roles,id'],
        'commission_percentage' => ['nullable','numeric','min:0'],
        'commission_percentage100' => ['nullable','numeric','min:0'],
        'commission_percentage120' => ['nullable','numeric','min:0'],
        'min_pct_to_qualify' => ['nullable','numeric','min:0','max:100'],
    ]);


        DB::beginTransaction();
        try {
            $row = CategoryCommission::updateOrCreate(
                ['category_id' => $data['category_id'], 'role_id' => $data['role_id']],
                [
                    'commission_percentage' => $data['commission_percentage'] ?? 0,
                    'commission_percentage100' => $data['commission_percentage100'] ?? 0,
                    'commission_percentage120' => $data['commission_percentage120'] ?? 0,
                    'min_pct_to_qualify' => $data['min_pct_to_qualify'] ?? 80,
                ]
            );


            DB::commit();
            return response()->json(['commission' => $row]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message'=>'Error saving commission','error'=>$e->getMessage()],500);
        }
    }

    // Delete commission config by id
    public function destroy($id)
    {
        $row = CategoryCommission::find($id);
        if (!$row) return response()->json(['message'=>'Not found'],404);
        $row->delete();
        return response()->json(['message'=>'Deleted']);
    }

    // Optional: bulk update (array of {category_id, commission_percentage})
public function bulkUpdate(Request $request)
{
    $payload = $request->validate([
        'role_id' => ['required','integer','exists:roles,id'],
        'items' => ['required','array'],
        'items.*.category_id' => ['required','integer','exists:categories,id'],
        'items.*.commission_percentage' => ['nullable','numeric','min:0'],
        'items.*.commission_percentage100' => ['nullable','numeric','min:0'],
        'items.*.commission_percentage120' => ['nullable','numeric','min:0'],
        'items.*.min_pct_to_qualify' => ['nullable','numeric','min:0','max:100'],
    ]);

    DB::beginTransaction();

    foreach ($payload['items'] as $it) {
        CategoryCommission::updateOrCreate(
            ['category_id' => $it['category_id'], 'role_id' => $payload['role_id']],
            [
                'commission_percentage' => $it['commission_percentage'] ?? 0,
                'commission_percentage100' => $it['commission_percentage100'] ?? 0,
                'commission_percentage120' => $it['commission_percentage120'] ?? 0,
                'min_pct_to_qualify' => $it['min_pct_to_qualify'] ?? 80,
            ]

        );
    }

    DB::commit();

    return response()->json(['message' => 'Bulk saved']);
}

}
