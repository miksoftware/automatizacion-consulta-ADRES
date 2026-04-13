<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Resultado;
use Illuminate\Http\JsonResponse;

class ConsultaCedulaController extends Controller
{
    /**
     * Retorna la información más reciente de un afiliado por cédula.
     *
     * GET /api/consulta/cedula/{cedula}
     *
     * @param  string  $cedula  Número de documento a consultar
     */
    public function show(string $cedula): JsonResponse
    {
        $resultado = Resultado::where('cedula', $cedula)
            ->where('exitosa', true)
            ->latest('consultado_en')
            ->first();

        if (! $resultado) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontraron resultados para la cédula proporcionada.',
                'data'    => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Consulta exitosa.',
            'data'    => [
                'cedula'            => $resultado->cedula,
                'tipo_documento'    => $resultado->tipo_documento,
                'nombres'           => $resultado->nombres,
                'apellidos'         => $resultado->apellidos,
                'fecha_nacimiento'  => $resultado->fecha_nacimiento,
                'departamento'      => $resultado->departamento,
                'municipio'         => $resultado->municipio,
                'estado_afiliacion' => $resultado->estado_afiliacion,
                'entidad_eps'       => $resultado->entidad_eps,
                'regimen'           => $resultado->regimen,
                'fecha_afiliacion'  => $resultado->fecha_afiliacion,
                'fecha_finalizacion'=> $resultado->fecha_finalizacion,
                'tipo_afiliado'     => $resultado->tipo_afiliado,
                'consultado_en'     => $resultado->consultado_en?->toIso8601String(),
            ],
        ]);
    }
}
