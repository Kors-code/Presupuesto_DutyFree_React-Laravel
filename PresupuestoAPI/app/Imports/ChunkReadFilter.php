<?php

namespace App\Imports;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class ChunkReadFilter implements IReadFilter
{
    private int $startRow = 0;
    private int $endRow = 0;

    /**
     * Set the chunk start row and size.
     *
     * @param int $startRow
     * @param int $chunkSize
     * @return void
     */
    public function setRows(int $startRow, int $chunkSize): void
    {
        $this->startRow = $startRow;
        $this->endRow   = $startRow + $chunkSize - 1;
    }

    /**
     * Decide whether to read a cell.
     *
     * @param string $column
     * @param int $row
     * @param string $worksheetName
     * @return bool
     */
    public function readCell($column, $row, $worksheetName = ''): bool
    {
        // Always read header row
        if ($row === 1) {
            return true;
        }

        return $row >= $this->startRow && $row <= $this->endRow;
    }
}
