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
    /* =========================
     * Helpers
     * ========================= */
    protected function logSkip(int $row, string $reason, array $assoc = [])
    {
        Log::warning('IMPORT SKIP', [
            'row' => $row,
            'reason' => $reason,
            'folio' => $assoc['folio'] ?? null,
            'pdv' => $assoc['pdv'] ?? null,
            'seller' => $assoc['vendedor'] ?? $assoc['seller'] ?? $assoc['vendor'] ?? null,
            'date' => $assoc['fecha'] ?? $assoc['date'] ?? null,
            'raw' => $assoc,
        ]);
    }

    protected function normalizeHeader(string $h): string
    {
        $h = preg_replace('/^\x{FEFF}/u', '', $h);
        $h = trim($h);
        $h = mb_strtolower($h);
        $h = preg_replace('/\s+/', ' ', $h);
        $h = preg_replace('/[^\p{L}\p{N}]+/u', '_', $h);
        $h = preg_replace('/_+/', '_', $h);
        return trim($h, '_');
    }

    protected function firstNotEmpty(array $row, array $keys)
    {
        foreach ($keys as $k) {
            if (!isset($row[$k])) continue;
            $v = trim((string)$row[$k]);
            if ($v !== '' && strtolower($v) !== 'null') return $v;
        }
        return null;
    }

    protected function parseNumber($v)
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '' || strtolower($s) === 'null') return null;

        $s = str_replace([' ', "\u{00A0}"], '', $s);

        if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
            $s = str_replace(',', '', $s);
        } elseif (strpos($s, ',') !== false) {
            $s = str_replace(',', '.', $s);
        }

        $s = preg_replace('/[^\d\.\-]/', '', $s);
        return is_numeric($s) ? (float)$s : null;
    }

    /* =========================
     * Import
     * ========================= */
    public function import(Request $request)
    {
        Log::info('IMPORT SALES START');

        $request->validate(['file' => 'required|file']);
        $file = $request->file('file');

        /* ===== Batch / checksum ===== */
        $checksum = hash('sha256', file_get_contents($file->getRealPath()));

        if ($existing = ImportBatch::where('checksum', $checksum)->first()) {
            return response()->json([
                'message' => 'Archivo ya importado',
                'batch_id' => $existing->id
            ], 409);
        }

        $batch = ImportBatch::create([
            'filename' => $file->getClientOriginalName(),
            'checksum' => $checksum,
            'status' => 'processing',
            'rows' => 0,
        ]);

        /* ===== Load sheet ===== */
        try {
            $sheet = IOFactory::load($file->getRealPath())->getActiveSheet();
        } catch (\Throwable $e) {
            $batch->update(['status' => 'failed', 'note' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }

        /* ===== Headers (VALIDAR ANTES DE ENTRAR AL BUCLE) ===== */
        $highestColumn = $sheet->getHighestColumn();
        $headerRange = $sheet->rangeToArray(
            'A1:' . $highestColumn . '1',
            null, true, true, true
        );

        $headerRaw = $headerRange ? reset($headerRange) : false;

        if (!$headerRaw || count(array_filter($headerRaw)) === 0) {
            $batch->update([
                'status' => 'failed',
                'note'   => 'El archivo no contiene encabezados válidos en la fila 1'
            ]);
            return response()->json(['error' => 'El archivo no tiene encabezados válidos en la fila 1'], 422);
        }

        $headers = [];
        foreach ($headerRaw as $col => $value) {
            $headers[$col] = $this->normalizeHeader((string)$value);
        }

        /* ===== Counters ===== */
        $processed = 0;
        $skipped   = 0;
        $created   = ['products' => 0, 'users' => 0, 'sales' => 0];
        $errors    = [];

        /* ===== Caches ===== */
        $productsCache   = [];
        $usersCache      = [];
        $categoriesCache = [];

        $chunkSize   = 500;
        $highestRow  = $sheet->getHighestRow();
        $salesBuffer = [];

        // DEFAULT SELLER ID fijo (usar tu ID ya creado)
        $DEFAULT_SELLER_ID = 40;
        // si quieres cachear el usuario por email, intenta cargarlo
        $defaultSellerModel = User::find($DEFAULT_SELLER_ID);
        if ($defaultSellerModel) {
            $usersCache['no-seller@system.local'] = $defaultSellerModel;
        }

        for ($start = 2; $start <= $highestRow; $start += $chunkSize) {

            $end = min($start + $chunkSize - 1, $highestRow);
            DB::beginTransaction();

            try {

                for ($row = $start; $row <= $end; $row++) {

                    try {
                        $range = $sheet->rangeToArray(
                            "A{$row}:{$highestColumn}{$row}",
                            null, true, true, true
                        );

                        $rowData = $range ? reset($range) : false;

                        // Fila vacía o inválida: saltar
                        if (!$rowData || count(array_filter($rowData)) === 0) {
                            $skipped++;
                            $this->logSkip($row, 'empty_row', $rowData ?: []);
                            continue;
                        }

                        /* ===== Map ===== */
                        $assoc = [];
                        foreach ($rowData as $c => $v) {
                            if (isset($headers[$c])) {
                                $assoc[$headers[$c]] = trim((string)$v);
                            }
                        }

                        /* ===== Seller (determinista, fallback al ID por defecto) ===== */
                        $sellerName = $this->firstNotEmpty($assoc, [
                            'vendedor', 'seller', 'vendor', 'vendedor_nombre', 'vendor_name'
                        ]);

                        if ($sellerName) {
                            $email = Str::slug($sellerName) . '@local';

                            if (!isset($usersCache[$email])) {
                                $usersCache[$email] = User::firstOrCreate(
                                    ['email' => strtolower($email)],
                                    ['name' => $sellerName]
                                );
                                if ($usersCache[$email]->wasRecentlyCreated) {
                                    $created['users']++;
                                }
                            }

                            $sellerId = $usersCache[$email]->id;
                        } else {
                            // Fallback: usar ID fijo
                            $sellerId = $DEFAULT_SELLER_ID;

                            // Log informativo (no crítico)
                            Log::info('IMPORT: seller fallback to SIN VENDEDOR', [
                                'row' => $row,
                                'seller_id' => $DEFAULT_SELLER_ID,
                                'folio' => $assoc['folio'] ?? null
                            ]);
                        }

                        /* ===== Date ===== */
                        $dateRaw = $this->firstNotEmpty($assoc, ['fecha', 'date']);
                        try {
                            if ($dateRaw) {
                                // tratar formatos comunes robustamente
                                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw)) {
                                    $saleDate = Carbon::createFromFormat('Y-m-d', $dateRaw)->toDateString();
                                } elseif (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $dateRaw)) {
                                    [$a, $b, $y] = explode('/', $dateRaw);
                                    if ((int)$a > 12) {
                                        $saleDate = Carbon::createFromFormat('d/m/Y', $dateRaw)->toDateString();
                                    } else {
                                        $saleDate = Carbon::createFromFormat('m/d/Y', $dateRaw)->toDateString();
                                    }
                                } else {
                                    $saleDate = Carbon::parse($dateRaw)->toDateString();
                                }
                            } else {
                                $saleDate = now()->toDateString();
                            }
                        } catch (\Throwable $e) {
                            $saleDate = now()->toDateString();
                        }

                        /* ===== Amounts ===== */
                        $qty = $this->parseNumber($this->firstNotEmpty($assoc, ['cantidad', 'qty', 'quantity'])) ?? 0;

                        $valorPesos = $this->parseNumber(
                            $this->firstNotEmpty($assoc, ['valor_en_pesos', 'value_pesos', 'valor_pesos', 'total'])
                        );

                        $valorUsd = $this->parseNumber(
                            $this->firstNotEmpty($assoc, ['valor_dolares', 'value_usd', 'valor_usd'])
                        );

                        $trm = $this->parseNumber(
                            $this->firstNotEmpty($assoc, ['t_cambio_costo'])
                        );

                        $amountCop = $valorPesos ?? (($valorUsd && $trm) ? round($valorUsd * $trm, 2) : 0);

                        /* ===== Folio / PDV (guardamos pero no bloqueamos por duplicado) ===== */
                        $folio = $this->firstNotEmpty($assoc, ['folio']);
                        $pdv   = $this->firstNotEmpty($assoc, ['pdv']);

                        /* ===== Product (cache) ===== */
                        $productKey = ($this->firstNotEmpty($assoc, ['codigo', 'product_code']) ?? 'x')
                            . '|' . ($this->firstNotEmpty($assoc, ['upc', 'upc1']) ?? 'x');

                           /* ===== Classification (SIN UNIFICAR) ===== */
                        $classificationRaw = $this->firstNotEmpty($assoc, ['clasificacion', 'classification']);
                        $classificationNorm = $classificationRaw !== null
                            ? trim((string)$classificationRaw)
                            : null;


                        if (!isset($productsCache[$productKey])) {
                            $productsCache[$productKey] = Product::firstOrCreate(
                                [
                                    'product_code' => $this->firstNotEmpty($assoc, ['codigo', 'product_code']),
                                    'upc'          => $this->firstNotEmpty($assoc, ['upc', 'upc1']),
                                ],
                                [
                                    'description'        => $this->firstNotEmpty($assoc, ['descripcion', 'description']),
                                    'classification'     => $classificationNorm,
                                    'classification_desc'=> $this->firstNotEmpty($assoc, ['descripcion_clasificacion', 'classification_desc']),
                                    'brand'              => $this->firstNotEmpty($assoc, ['brand', 'marca']),
                                    'currency'           => $this->firstNotEmpty($assoc, ['moneda', 'currency']),
                                ]
                            );
                            if ($productsCache[$productKey]->wasRecentlyCreated) {
                                $created['products']++;
                            }
                        }
                        $product = $productsCache[$productKey];

                        /* ===== Category (cache) ===== */
                        $classificationForCategory = $classificationNorm ?? $this->firstNotEmpty($assoc, ['clasificacion', 'classification']);

                        $categoryKey = null;
                        if ($classificationForCategory !== null) {
                            $categoryKey = (string) $classificationForCategory;
                            $categoryKey = mb_strtolower(trim($categoryKey));
                        }

                        if ($categoryKey && !isset($categoriesCache[$categoryKey])) {
                            $categoriesCache[$categoryKey] = Category::firstOrCreate(
                                ['classification_code' => $categoryKey],
                                [
                                    'name' => $this->firstNotEmpty($assoc, ['descripcion_clasificacion', 'classification_desc']) ?? $categoryKey,
                                    'description' => $this->firstNotEmpty($assoc, ['descripcion_clasificacion', 'classification_desc']),
                                ]
                            );
                        }

                        /* ===== Buffer sale ===== */
                        $salesBuffer[] = [
                            'import_batch_id' => $batch->id,
                            'seller_id' => $sellerId,
                            'product_id' => $product->id,
                            'sale_date'  => $saleDate,
                            'amount'     => $amountCop,
                            'amount_cop' => $amountCop,
                            'value_pesos'=> $valorPesos,
                            'value_usd'  => $valorUsd,
                            'exchange_rate'=>$trm,
                            'currency'   => $this->firstNotEmpty($assoc, ['moneda', 'currency']),
                            'quantity'   => $qty,
                            'folio'      => $folio,
                            'pdv'        => $pdv,
                            'cashier'    => $this->firstNotEmpty($assoc, ['cajero', 'cashier']),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        if (count($salesBuffer) >= 500) {
                            Sale::insert($salesBuffer);
                            $created['sales'] += count($salesBuffer);
                            $salesBuffer = [];
                        }

                        $processed++;

                    } catch (\Throwable $rowEx) {
                        $skipped++;
                        $errors[] = ['row' => $row, 'error' => $rowEx->getMessage()];
                        Log::error("Row {$row} error: " . $rowEx->getMessage(), [
                            'trace' => $rowEx->getTraceAsString(),
                            'data' => $assoc ?? null
                        ]);
                    }
                }

                if ($salesBuffer) {
                    Sale::insert($salesBuffer);
                    $created['sales'] += count($salesBuffer);
                    $salesBuffer = [];
                }

                DB::commit();

            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error("Chunk {$start}-{$end} failed: " . $e->getMessage());
                $errors[] = ['chunk' => "{$start}-{$end}", 'error' => $e->getMessage()];
                // no hacemos continue; seguimos con el siguiente chunk
            }
        }

        $batch->update([
            'status' => 'done',
            'rows' => $processed
        ]);

        return response()->json([
            'message' => 'Importación completada',
            'processed' => $processed,
            'skipped' => $skipped,
            'created' => $created,
            'errors' => $errors,
            'batch_id' => $batch->id
        ]);
    }
}
