<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class CedulasImport implements ToArray
{
    protected array $cedulas = [];

    public function array(array $rows): void
    {
        foreach ($rows as $row) {
            // Tomar el primer valor de cada fila (primera columna)
            $cedula = is_array($row) ? ($row[0] ?? null) : $row;
            
            if ($cedula && is_numeric($cedula)) {
                $this->cedulas[] = (string) $cedula;
            }
        }
    }

    public function getCedulas(): array
    {
        return array_unique(array_filter($this->cedulas));
    }
}
