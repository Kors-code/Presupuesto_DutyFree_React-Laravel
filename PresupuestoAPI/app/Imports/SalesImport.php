<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\User;
use App\Models\Sale;
use App\Models\Category;
use App\Models\CategoryCommission;
use App\Models\Commission;
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
        $h = trim($h, '_');
        return $h;
    }

    protected function firstNotEmpty(array $row, array $keys) {
        foreach ($keys as $k) {
            if (isset($row[$k]) && $row[$k] !== null && $row[$k] !== '') return $row[$k];
        }
        return null;
    }

    public function import(Request $request)
    {
        Log::info("IMPORT endpoint called");
        // quick debug return if needed (uncomment when debugging)
        // return response()->json(['debug'=>'hit'],200);

        $request->validate(['file' => 'required|file']);

        // Load spreadsheet
        $path = $request->file('file')->getRealPath();

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (\Throwable $e) {
            Log::error("Spreadsheet load error: ".$e->getMessage());
            return response()->json(['message'=>'No se pudo leer el archivo xlsx/csv','error'=>$e->getMessage()],500);
        }

        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null,true,true,true);

        if (count($rows) < 1) {
            return response()->json(['message'=>'Archivo vacío'], 422);
        }

        // normalize header
        $headerRow = array_shift($rows);
        $normalizedHeader = [];
        foreach ($headerRow as $col => $value) {
            $normalizedHeader[$col] = $this->normalizeHeader((string)$value);
        }
        Log::info('Normalized headers: '.json_encode(array_values($normalizedHeader)));

        $processed = 0;
        $skipped = 0;
        $errors = [];
        $created = ['products' => 0,'users' => 0,'sales' => 0,'commissions' => 0];

        DB::beginTransaction();
        try {
            foreach ($rows as $rIndex => $row) {
                $rowNumber = $rIndex + 2; // approximate excel row (header=1)
                try {
                    // map row into assoc by normalized keys
                    $assoc = [];
                    foreach ($row as $col => $value) {
                        $key = $normalizedHeader[$col] ?? null;
                        if ($key) $assoc[$key] = is_string($value) ? trim($value) : $value;
                    }

                    // required: seller (vendedor) or seller code
                    $sellerName = $this->firstNotEmpty($assoc, ['vendedor','vendor','vendedor_nombre','vendor_name']);
                    $sellerCode = $this->firstNotEmpty($assoc, ['codigo_vendedor','codigovendedor','vendor_code']);

                    if (empty($sellerName) && empty($sellerCode)) {
                        $skipped++;
                        continue;
                    }

                    // parse fields (same logic you had)
                    $fechaRaw = $this->firstNotEmpty($assoc, ['fecha','date']);
                    $folio = $this->firstNotEmpty($assoc, ['folio']);
                    $pdv = $this->firstNotEmpty($assoc, ['pdv']);
                    $productCode = $this->firstNotEmpty($assoc, ['codigo','product_code','codigo_producto']);
                    $upc = $this->firstNotEmpty($assoc, ['upc1','upc']);
                    $description = $this->firstNotEmpty($assoc, ['descripcion','description']);
                    $qtyRaw = $this->firstNotEmpty($assoc, ['cantidad','quantity']);
                    $qty = $qtyRaw !== null ? floatval(str_replace([',',' '], ['',''], $qtyRaw)) : 0;
                    $costRaw = $this->firstNotEmpty($assoc, ['costo_de_venta','cost','cost_usd','costo_de_venta_usd']);
                    $cost = $costRaw !== null ? floatval(str_replace([',',' '], ['',''], $costRaw)) : null;
                    $valorPesosRaw = $this->firstNotEmpty($assoc, ['valor_en_pesos','value_pesos','valor_pesos','valor_en_pesos','total']);
                    $valorPesos = $valorPesosRaw !== null ? floatval(str_replace([',',' '], ['',''], $valorPesosRaw)) : null;
                    $valorUsdRaw = $this->firstNotEmpty($assoc, ['valor_dolares','value_usd','valor_usd']);
                    $valorUsd = $valorUsdRaw !== null ? floatval(str_replace([',',' '], ['',''], $valorUsdRaw)) : null;
                    $currency = $this->firstNotEmpty($assoc, ['moneda','currency']);
                    $regularPriceRaw = $this->firstNotEmpty($assoc, ['precio_regular','regular_price','precio_en_dlls']);
                    $regularPrice = $regularPriceRaw !== null ? floatval(str_replace([',',' '], ['',''], $regularPriceRaw)) : null;
                    $classification = $this->firstNotEmpty($assoc, ['clasificacion','classification']);
                    $providerCode = $this->firstNotEmpty($assoc, ['codigo_proveedor','provider_code']);
                    $providerName = $this->firstNotEmpty($assoc, ['proveedor','provider_name']);
                    $brand = $this->firstNotEmpty($assoc, ['brand','marca']);
                    $cashierName = $this->firstNotEmpty($assoc, ['cajero','cashier']);

                    // parse date
                    $saleDate = null;
                    if ($fechaRaw) {
                        try { $saleDate = Carbon::createFromFormat('d/m/Y', $fechaRaw)->toDateString(); }
                        catch (\Throwable $e) { try { $saleDate = Carbon::parse($fechaRaw)->toDateString(); } catch (\Throwable $e) { $saleDate = null; } }
                    }

                    // PRODUCT
                    $product = null;
                    if ($productCode || $upc) {
                        $product = Product::firstOrCreate(
                            ['product_code' => $productCode ?: null, 'upc' => $upc ?: null],
                            ['description' => $description,'brand' => $brand,'classification' => $classification,'classification_desc' => $this->firstNotEmpty($assoc,['descripcion_clasificacion','descripcion__clasificacion']),'provider_code' => $providerCode,'provider_name' => $providerName,'regular_price' => $regularPrice,'cost_usd' => $cost,'currency' => $currency]
                        );
                        $product->fill(['description'=>$product->description ?? $description,'brand'=>$product->brand ?? $brand,'classification'=>$product->classification ?? $classification]);
                        $product->save();
                        $created['products']++;
                    }

                    // SELLER
                    $sellerEmail = 'seller_' . ($sellerCode ? preg_replace('/[^A-Za-z0-9]/','',$sellerCode) : Str::slug($sellerName)) . '@local';
                    $seller = User::firstOrCreate(['email' => strtolower($sellerEmail)], ['name' => $sellerName ?: $sellerEmail]);
                    $created['users']++;

                    // CASHIER
                    $cashier = null;
                    if (!empty($cashierName)) {
                        $cashierEmail = 'cashier_' . Str::slug($cashierName) . '@local';
                        $cashier = User::firstOrCreate(['email' => strtolower($cashierEmail)], ['name' => $cashierName]);
                        $created['users']++;
                    }

                    // amount calculation
                    $amount = $valorUsd ?? $valorPesos ?? null;
                    if ($amount === null) {
                        if ($qty && $regularPrice) $amount = $qty * $regularPrice;
                        else $amount = 0;
                    }

                    // duplicate check
                    $duplicate = false;
                    if ($folio && $pdv && $saleDate) {
                        $duplicate = Sale::where('folio',$folio)->where('pdv',$pdv)->whereDate('sale_date',$saleDate)->exists();
                    }
                    if ($duplicate) { $skipped++; continue; }

                    $sale = Sale::create([
                        'seller_id' => $seller->id,
                        'cashier_id' => $cashier ? $cashier->id : null,
                        'product_id' => $product ? $product->id : null,
                        'amount' => $amount,
                        'sale_date' => $saleDate ?? now()->toDateString(),
                        'folio' => $folio ?? null,
                        'pdv' => $pdv ?? null,
                        'quantity' => $qty,
                        'value_pesos' => $valorPesos,
                        'value_usd' => $valorUsd,
                        'currency' => $currency,
                        'cost' => $cost
                    ]);
                    $created['sales']++;

                    // Create category if missing
                    $catName = $product?->classification ?? $classification;
                    $category = null;
                    if ($catName) $category = Category::firstOrCreate(['name' => $catName]);

                    // Attempt to compute commissions if rules exist
                    if ($category) {
                        // seller role at date
                        $sellerRoleName = optional($seller->roleAtDate($sale->sale_date)->role)->name;
                        if ($sellerRoleName) {
                            $role = \App\Models\Role::where('name',$sellerRoleName)->first();
                            if ($role) {
                                $rule = CategoryCommission::where('category_id',$category->id)->where('role_id',$role->id)->first();
                                if ($rule && $rule->commission_percentage > 0 && $amount>0) {
                                    $commissionAmount = round($amount * ($rule->commission_percentage/100.0), 2);
                                    // Commission:: may lack rule_id column (we added later), use safe create
                                    $commission = new Commission([
                                        'sale_id' => $sale->id,
                                        'user_id' => $seller->id,
                                        'commission_amount' => $commissionAmount,
                                        'calculated_as' => $sellerRoleName,
                                    ]);
                                    // try to set rule_id & is_provisional if columns exist
                                    if (Schema::hasColumn('commissions','rule_id')) $commission->rule_id = $rule->id;
                                    if (Schema::hasColumn('commissions','is_provisional')) $commission->is_provisional = true;
                                    $commission->save();
                                    $created['commissions']++;
                                }
                            }
                        }
                        // cashier same logic
                        if ($cashier) {
                            $cashierRoleName = optional($cashier->roleAtDate($sale->sale_date)->role)->name;
                            if ($cashierRoleName) {
                                $role = \App\Models\Role::where('name',$cashierRoleName)->first();
                                if ($role) {
                                    $rule = CategoryCommission::where('category_id',$category->id)->where('role_id',$role->id)->first();
                                    if ($rule && $rule->commission_percentage > 0 && $amount>0) {
                                        $commissionAmount = round($amount * ($rule->commission_percentage/100.0), 2);
                                        $commission = new Commission([
                                            'sale_id' => $sale->id,
                                            'user_id' => $cashier->id,
                                            'commission_amount' => $commissionAmount,
                                            'calculated_as' => $cashierRoleName,
                                        ]);
                                        if (Schema::hasColumn('commissions','rule_id')) $commission->rule_id = $rule->id;
                                        if (Schema::hasColumn('commissions','is_provisional')) $commission->is_provisional = true;
                                        $commission->save();
                                        $created['commissions']++;
                                    }
                                }
                            }
                        }
                    }

                    $processed++;
                } catch (\Throwable $rowEx) {
                    $errors[] = ['row' => $rowNumber, 'message' => $rowEx->getMessage()];
                    Log::error("Import row error (row {$rowNumber}): ".$rowEx->getMessage());
                    // continue with other rows
                }
            } // end foreach

            DB::commit();

            return response()->json([
                'message' => 'Importación completada',
                'processed' => $processed,
                'rows' => $processed,
                'skipped' => $skipped,
                'created' => $created,
                'errors' => $errors,
                'batch_id' => null
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Import error: ".$e->getMessage());
            return response()->json(['message'=>'Error durante importación','error'=>$e->getMessage()], 500);
        }
    }
}
