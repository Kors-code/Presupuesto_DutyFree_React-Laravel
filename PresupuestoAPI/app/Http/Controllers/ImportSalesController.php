<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\User;
use App\Models\Sale;
use App\Models\Category;
use App\Models\ImportBatch;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportSalesController extends Controller
{
    protected function normalizeHeader(string $h): string {
        $h = preg_replace('/^\x{FEFF}/u','',$h);
        $h = trim($h);
        $h = mb_strtolower($h);
        $h = preg_replace('/\s+/', ' ', $h);
        $h = preg_replace('/[^\p{L}\p{N}]+/u', '_', $h);
        $h = preg_replace('/_+/', '_', $h);
        return trim($h, '_');
    }

    // Regresa el primer campo no vacío
    protected function firstNotEmpty(array $row, array $keys) {
        foreach ($keys as $k) {
            if (!isset($row[$k])) continue;
            $v = trim((string)$row[$k]);
            if ($v !== '' && strtolower($v) !== 'null') return $v;
        }
        return null;
    }

    public function import(Request $request)
    {
        Log::info("IMPORT endpoint called");

        $request->validate(['file' => 'required|file']);

        $file = $request->file('file');

        // ===== Crear o verificar BATCH (checksum) =====
        $contents = file_get_contents($file->getRealPath());
        $checksum = hash('sha256', $contents);

        if ($existing = ImportBatch::where('checksum', $checksum)->first()) {
            return response()->json([
                'message' => 'Archivo ya importado anteriormente',
                'batch_id' => $existing->id
            ], 409);
        }

        $batch = ImportBatch::create([
            'filename' => $file->getClientOriginalName(),
            'checksum' => $checksum,
            'status' => 'processing',
            'rows' => 0,
        ]);

        // ===== Leer archivo =====
        try {
            $sheet = IOFactory::load($file->getRealPath())->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);
        } catch (\Throwable $e) {
            $batch->update(['status'=>'failed','note'=>$e->getMessage()]);
            return response()->json(['error'=>$e->getMessage()],500);
        }

        if (count($rows) <= 1) {
            $batch->update(['status'=>'failed','note'=>'Archivo vacío']);
            return response()->json(['message'=>'Archivo vacío'],422);
        }

        // ===== Normalizar encabezados =====
        $headerRaw = array_shift($rows);
        $headers = [];
        foreach ($headerRaw as $col => $value) {
            $headers[$col] = $this->normalizeHeader($value ?? '');
        }

        $processed = 0;
        $skipped = 0;
        $errors = [];
        $created = ['products'=>0, 'users'=>0, 'sales'=>0];

        DB::beginTransaction();
        try {

            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2;

                try {
                    // =======================
                    // MAPEO DEL ROW
                    // =======================
                    $assoc = [];
                    foreach ($row as $c => $val) {
                        $key = $headers[$c] ?? null;
                        if ($key) $assoc[$key] = trim((string)$val);
                    }

                    Log::info("ROW NORMALIZED: " . json_encode($assoc));

                    // =======================
                    // CAMPOS NECESARIOS
                    // =======================
                    $sellerName = $this->firstNotEmpty($assoc, ['vendedor','seller','vendor']);
                    if (!$sellerName) { $skipped++; continue; }

                    $productCode = $this->firstNotEmpty($assoc, ['codigo','product_code']);
                    $upc = $this->firstNotEmpty($assoc, ['upc','upc1']);
                    $description = $this->firstNotEmpty($assoc, ['descripcion','description']);
                    $classification = $this->firstNotEmpty($assoc, ['clasificacion','classification']);

                    $qty = floatval($this->firstNotEmpty($assoc, ['cantidad','qty']) ?? 0);
                    $amount = floatval($this->firstNotEmpty($assoc, ['valor_en_pesos','total','valor_pesos']) ?? 0);

                    $folio = $this->firstNotEmpty($assoc, ['folio']);
                    $pdv = $this->firstNotEmpty($assoc, ['pdv']);

                    $dateRaw = $this->firstNotEmpty($assoc, ['fecha','date']);
                    $saleDate = $dateRaw ? Carbon::parse($dateRaw)->format('Y-m-d') : now()->toDateString();

                    // =======================
                    // PREVENIR DUPLICADOS
                    // =======================
                    if ($folio && $pdv) {
                        $exists = Sale::where('folio', $folio)
                            ->where('pdv', $pdv)
                            ->whereDate('sale_date', $saleDate)
                            ->exists();
                        if ($exists) { $skipped++; continue; }
                    }

                    // =======================
                    // CREAR / ACTUALIZAR PRODUCTO
                    // =======================
                    $product = Product::firstOrCreate(
                        ['product_code' => $productCode, 'upc' => $upc],
                        [
                            'description' => $description,
                            'brand' => $this->firstNotEmpty($assoc, ['brand','marca']),
                            'classification' => $classification,
                            'provider_code' => $this->firstNotEmpty($assoc,['codigo_proveedor']),
                            'provider_name' => $this->firstNotEmpty($assoc,['proveedor','provider']),
                        ]
                    );

                    if ($product->wasRecentlyCreated) $created['products']++;

                    // =======================
                    // CREAR / ENCONTRAR USUARIO VENDEDOR
                    // =======================
                    $seller = User::firstOrCreate(
                        ['email' => Str::slug($sellerName).'@local'],
                        ['name' => $sellerName]
                    );

                    if ($seller->wasRecentlyCreated) $created['users']++;

                    // =======================
                    // CREAR / ACTUALIZAR CATEGORÍA
                    // =======================
                    if ($classification) {
                        Category::firstOrCreate(
                            ['classification_code' => $classification],
                            ['name' => $classification]
                        );
                    }

                    // =======================
                    // CREAR VENTA
                    // =======================
                    Sale::create([
                        'import_batch_id' => $batch->id,
                        'seller_id' => $seller->id,
                        'product_id' => $product->id,
                        'amount' => $amount,
                        'quantity' => $qty,
                        'folio' => $folio,
                        'pdv' => $pdv,
                        'sale_date' => $saleDate
                    ]);

                    $created['sales']++;
                    $processed++;

                } catch (\Throwable $ex) {
                    Log::error("Error row {$rowNumber}: ".$ex->getMessage());
                    $errors[] = ['row'=>$rowNumber,'error'=>$ex->getMessage()];
                }
            }

            DB::commit();

            $batch->update([
                'status'=>'done',
                'rows'=>$processed
            ]);

            return response()->json([
                'message'=>'Importación completada',
                'processed'=>$processed,
                'skipped'=>$skipped,
                'created'=>$created,
                'errors'=>$errors,
                'batch_id'=>$batch->id
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            $batch->update(['status'=>'failed','note'=>$e->getMessage()]);
            return response()->json(['error'=>$e->getMessage()],500);
        }
    }
}
