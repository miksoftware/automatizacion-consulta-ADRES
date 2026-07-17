<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ResultadosConsolidadosExport implements FromArray, WithHeadings, WithStyles
{
    protected array $resultados;

    public function __construct(array $resultados)
    {
        $this->resultados = $resultados;
    }

    public function array(): array
    {
        return array_map(function ($item) {
            $error = $item['error'] ?? '';
            if (!empty($error)) {
                $error = preg_replace('/^(Timeout:\s*|Error:\s*)/i', '', $error);
            }

            return [
                $this->valorOTexto($item['nombre_archivo'] ?? null),
                $this->valorOTexto($item['cedula'] ?? null),
                $this->valorOTexto($item['tipo_documento'] ?? 'CC'),
                $this->valorOTexto($item['nombres'] ?? null),
                $this->valorOTexto($item['apellidos'] ?? null),
                $this->valorOTexto($item['fecha_nacimiento'] ?? null),
                $this->valorOTexto($item['departamento'] ?? null),
                $this->valorOTexto($item['municipio'] ?? null),
                $this->valorOTexto($item['estado'] ?? null),
                $this->valorOTexto($item['entidad_eps'] ?? null),
                $this->valorOTexto($item['regimen'] ?? null),
                $this->valorOTexto($item['fecha_afiliacion'] ?? null),
                $this->valorOTexto($item['fecha_finalizacion'] ?? null),
                $this->valorOTexto($item['tipo_afiliado'] ?? null),
                $this->valorOTexto($error),
            ];
        }, $this->resultados);
    }

    protected function valorOTexto(mixed $valor): string
    {
        if ($valor === null) {
            return 'SIN DATOS';
        }

        $texto = trim((string) $valor);

        return $texto === '' ? 'SIN DATOS' : $texto;
    }

    public function headings(): array
    {
        return [
            'Nombre del Archivo',
            'Cedula',
            'Tipo Doc',
            'Nombres',
            'Apellidos',
            'Fecha Nacimiento',
            'Departamento',
            'Municipio',
            'Estado',
            'Entidad/EPS',
            'Regimen',
            'Fecha Afiliacion',
            'Fecha Finalizacion',
            'Tipo Afiliado',
            'Error',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '3498DB'],
                ],
            ],
        ];
    }
}
