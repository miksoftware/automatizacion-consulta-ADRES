<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ResultadosExport implements FromArray, WithHeadings, WithStyles
{
    protected array $resultados;

    public function __construct(array $resultados)
    {
        $this->resultados = $resultados;
    }

    public function array(): array
    {
        return array_map(function ($item) {
            return [
                $item['cedula'] ?? '',
                $item['tipo_documento'] ?? 'CC',
                $item['nombres'] ?? '',
                $item['apellidos'] ?? '',
                $item['fecha_nacimiento'] ?? '',
                $item['departamento'] ?? '',
                $item['municipio'] ?? '',
                $item['estado'] ?? '',
                $item['entidad_eps'] ?? '',
                $item['regimen'] ?? '',
                $item['fecha_afiliacion'] ?? '',
                $item['fecha_finalizacion'] ?? '',
                $item['tipo_afiliado'] ?? '',
                $item['error'] ?? '',
            ];
        }, $this->resultados);
    }

    public function headings(): array
    {
        return [
            'Cédula',
            'Tipo Doc',
            'Nombres',
            'Apellidos',
            'Fecha Nacimiento',
            'Departamento',
            'Municipio',
            'Estado',
            'Entidad/EPS',
            'Régimen',
            'Fecha Afiliación',
            'Fecha Finalización',
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
