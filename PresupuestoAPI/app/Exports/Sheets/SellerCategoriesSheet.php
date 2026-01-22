<?php

namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class SellerCategoriesSheet implements FromArray, WithHeadings, WithTitle, WithEvents
{
    protected array $rows;
    protected array $meta;

    public function __construct(array $rows, array $meta = [])
    {
        $this->rows = $rows;
        $this->meta = $meta;
    }

    public function array(): array
    {
        return $this->rows;
    }

    // 👇 dejamos espacio para encabezados personalizados
    public function headings(): array
    {
        return [
            'Categoría',
            'Ventas USD',
            'Ventas COP',
            'PPTO USD',
            '% Cumplimiento',
            '% Comisión',
            'Comisión USD',
            'Comisión COP',
        ];
    }

    public function title(): string
    {
        return 'Categorias';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {

                // columnas A → H
                $lastColumn = 'H';

                $seller = $this->meta['user']['name'] ?? 'Vendedor';
                $budget = $this->meta['budget']['name'] ?? '';
                $start  = $this->meta['budget']['start_date'] ?? '';
                $end    = $this->meta['budget']['end_date'] ?? '';

                // 🔹 FILA 1: Nombre vendedor
                $event->sheet->mergeCells("A1:{$lastColumn}1");
                $event->sheet->setCellValue(
                    'A1',
                    strtoupper($seller) . ' — Detalle de Comisiones'
                );

                // 🔹 FILA 2: Presupuesto / Periodo
                $event->sheet->mergeCells("A2:{$lastColumn}2");
                $event->sheet->setCellValue(
                    'A2',
                    "Presupuesto: {$budget} | Periodo: {$start} → {$end}"
                );

                // estilos
                $event->sheet->getStyle("A1")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14],
                    'alignment' => ['horizontal' => 'center'],
                ]);

                $event->sheet->getStyle("A2")->applyFromArray([
                    'font' => ['italic' => true, 'size' => 10],
                    'alignment' => ['horizontal' => 'center'],
                ]);

                // mover headings a fila 4
                $event->sheet->insertNewRowBefore(3, 1);
            },
        ];
    }
}
