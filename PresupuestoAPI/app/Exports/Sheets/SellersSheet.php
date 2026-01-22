<?php
namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class SellersSheet implements FromArray, WithHeadings, WithTitle
{
    protected $rows;
    protected $meta;

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
            'User ID',
            'Vendedor',
            'Turnos asignados',
            'Ventas COP',
            'Ventas USD',
            'Comisión COP',
            'Avg TRM',
            'Tickets count',
            'Avg ticket USD',
            'Avg ticket COP',
        ];
    }

    public function title(): string
    {
        return 'Sellers';
    }
}
