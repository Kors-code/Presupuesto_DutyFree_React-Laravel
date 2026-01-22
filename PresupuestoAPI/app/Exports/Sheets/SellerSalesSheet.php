<?php

namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class SellerSalesSheet implements FromArray, WithHeadings, WithTitle, WithEvents
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

    public function headings(): array
    {
        return [
            'Fecha',
            'Folio',
            'Producto',
            'Categoría',
            'USD',
            'COP',
            'Comisión',
            'Estado',
        ];
    }

    public function title(): string
    {
        return 'Ventas';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {

                $lastColumn = 'H';

                $seller = $this->meta['user']['name'] ?? 'Vendedor';
                $budget = $this->meta['budget']['name'] ?? '';
                $start  = $this->meta['budget']['start_date'] ?? '';
                $end    = $this->meta['budget']['end_date'] ?? '';

                $event->sheet->mergeCells("A1:{$lastColumn}1");
                $event->sheet->setCellValue(
                    'A1',
                    strtoupper($seller) . ' — Detalle de Ventas'
                );

                $event->sheet->mergeCells("A2:{$lastColumn}2");
                $event->sheet->setCellValue(
                    'A2',
                    "Presupuesto: {$budget} | Periodo: {$start} → {$end}"
                );

                $event->sheet->getStyle("A1")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14],
                    'alignment' => ['horizontal' => 'center'],
                ]);

                $event->sheet->getStyle("A2")->applyFromArray([
                    'font' => ['italic' => true, 'size' => 10],
                    'alignment' => ['horizontal' => 'center'],
                ]);

                $event->sheet->insertNewRowBefore(3, 1);
            },
        ];
    }
}
