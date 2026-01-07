<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ImportBatch;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ImportBatchController extends Controller
{
    // GET /api/v1/imports
    public function index()
    {
        $batches = ImportBatch::orderBy('created_at', 'desc')
            ->select(['id','filename','checksum','status','rows','created_at','note'])
            ->get();

        return response()->json($batches);
    }

    // GET /api/v1/imports/{id}
    public function show($id)
    {
        $batch = ImportBatch::with(['sales' => function($q) {
            $q->select('id','sale_date','folio','pdv','product_id','quantity','amount','value_pesos','value_usd','currency','cashier','import_batch_id');
        }])->findOrFail($id);

        return response()->json($batch);
    }

    // DELETE /api/v1/imports/{id}
    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $batch = ImportBatch::findOrFail($id);

            // 1) Borrar ventas asociadas explícitamente
            Sale::where('import_batch_id', $batch->id)->delete();

            // 2) Borrar registro del batch
            $filename = $batch->filename;
            $batch->delete();

            // 3) Intentar borrar archivo físico si existe (varias ubicaciones probables)
            try {
                // Si guardas en storage/app/imports
                $path1 = 'imports/' . $filename;
                if ($filename && Storage::exists($path1)) {
                    Storage::delete($path1);
                } else {
                    // si guardaste en public/uploads o similar:
                    $path2 = public_path('uploads/' . $filename);
                    if ($filename && file_exists($path2)) unlink($path2);
                    // si guardaste en storage/app/public
                    $path3 = storage_path('app/public/' . $filename);
                    if ($filename && file_exists($path3)) unlink($path3);
                }
            } catch (\Throwable $e) {
                Log::warning("No se pudo borrar archivo físico: " . $e->getMessage());
            }

            DB::commit();
            return response()->json(['message' => 'Batch eliminado y ventas asociadas borradas.']);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Error al eliminar import batch: " . $e->getMessage());
            return response()->json(['error' => 'No se pudo eliminar el batch', 'detail' => $e->getMessage()], 500);
        }
    }
}
