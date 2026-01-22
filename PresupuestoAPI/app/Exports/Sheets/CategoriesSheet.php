<?php
namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class CategoriesSheet implements FromArray, WithHeadings, WithTitle
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
            'Classification',
            'Participación %',
            'Budget (USD)',
            'Ventas (USD)',
            'Ventas (COP)',
            '% de categoría',
            'Califica',
            'Pct comisión aplicada',
            'Comisión proyectada USD',
            'Comisión COP',
        ];
    }

    public function title(): string
    {
        return 'Categories';
    }
}
