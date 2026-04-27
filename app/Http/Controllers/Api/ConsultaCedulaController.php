<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Resultado;
use Illuminate\Http\JsonResponse;

class ConsultaCedulaController extends Controller
{
    /**
     * Retorna el historial completo de consultas de un afiliado por cédula,
     * ordenado del más reciente al más antiguo.
     *
     * GET /api/consulta/cedula/{cedula}
     *
     * @param  string  $cedula  Número de documento a consultar
     */
    public function show(string $cedula): JsonResponse
    {
        $resultados = Resultado::where('cedula', $cedula)
            ->where('exitosa', true)
            ->latest('consultado_en')
            ->get();

        if ($resultados->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontraron resultados para la cédula proporcionada.',
                'data'    => null,
            ], 404);
        }

        $data = $resultados->map(fn (Resultado $r) => [
            'cedula'            => $r->cedula,
            'tipo_documento'    => $r->tipo_documento,
            'nombres'           => $r->nombres,
            'apellidos'         => $r->apellidos,
            'fecha_nacimiento'  => $r->fecha_nacimiento,
            'departamento'      => $r->departamento,
            'municipio'         => $r->municipio,
            'estado_afiliacion' => $r->estado_afiliacion,
            'entidad_eps'       => $r->entidad_eps,
            'regimen'           => $r->regimen,
            'fecha_afiliacion'  => $r->fecha_afiliacion,
            'fecha_finalizacion'=> $r->fecha_finalizacion,
            'tipo_afiliado'     => $r->tipo_afiliado,
            'consultado_en'     => $r->consultado_en?->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Consulta exitosa.',
            'total'   => $data->count(),
            'data'    => $data,
        ]);
    }
}
