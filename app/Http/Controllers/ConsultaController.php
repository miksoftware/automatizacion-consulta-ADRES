<?php

namespace App\Http\Controllers;

use App\Imports\CedulasImport;
use App\Exports\ResultadosExport;
use App\Models\Consulta;
use App\Models\Resultado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ConsultaController extends Controller
{
    /**
     * Segundos promedio por cédula (incluye reintentos posibles).
     */
    private const SEGUNDOS_POR_CEDULA = 45;

    public function index()
    {
        $consultas = Consulta::orderBy('created_at', 'desc')->paginate(15);
        return view('consultas.index', compact('consultas'));
    }

    /**
     * Pre-valida el archivo: lee cédulas y retorna conteo + tiempo estimado.
     * El usuario decide si proceder antes de iniciar el proceso largo.
     */
    public function validar(Request $request): JsonResponse
    {
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $archivo = $request->file('archivo');
        $ruta = $archivo->store('uploads', 'public');

        try {
            $import = new CedulasImport();
            Excel::import($import, Storage::disk('public')->path($ruta));
            $cedulas = $import->getCedulas();
        } catch (\Exception $e) {
            Storage::disk('public')->delete($ruta);
            return response()->json([
                'ok' => false,
                'error' => 'No se pudo leer el archivo. Verifique el formato.',
            ]);
        }

        if (empty($cedulas)) {
            Storage::disk('public')->delete($ruta);
            return response()->json([
                'ok' => false,
                'error' => 'No se encontraron cédulas numéricas válidas en el archivo.',
            ]);
        }

        $total = count($cedulas);
        $segundosEstimado = $total * self::SEGUNDOS_POR_CEDULA;

        return response()->json([
            'ok' => true,
            'total_cedulas' => $total,
            'tiempo_estimado_segundos' => $segundosEstimado,
            'tiempo_estimado_texto' => $this->formatearTiempo($segundosEstimado),
            'archivo_nombre' => $archivo->getClientOriginalName(),
            'archivo_ruta' => $ruta,
        ]);
    }

    public function procesar(Request $request): JsonResponse
    {
        // Si viene de la pre-validación, usar la ruta ya guardada
        $ruta = $request->input('archivo_ruta');
        $nombreOriginal = $request->input('archivo_nombre', 'archivo.xlsx');

        if (!$ruta || !Storage::disk('public')->exists($ruta)) {
            // Fallback: subir archivo directamente
            $request->validate([
                'archivo' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            ]);
            $archivo = $request->file('archivo');
            $nombreOriginal = $archivo->getClientOriginalName();
            $ruta = $archivo->store('uploads', 'public');
        }

        // Leer cédulas
        $import = new CedulasImport();
        Excel::import($import, Storage::disk('public')->path($ruta));
        $cedulas = $import->getCedulas();

        if (empty($cedulas)) {
            Storage::disk('public')->delete($ruta);
            return response()->json([
                'ok' => false,
                'error' => 'No se encontraron cédulas válidas en el archivo.',
            ]);
        }

        // Crear consulta
        $consulta = Consulta::create([
            'archivo_entrada' => $nombreOriginal,
            'archivo_entrada_path' => $ruta,
            'total_cedulas' => count($cedulas),
            'estado' => 'pendiente',
        ]);

        // Despachar job al queue worker (background real)
        \App\Jobs\ProcesarConsultaJob::dispatch($consulta->id, $cedulas);

        return response()->json([
            'ok' => true,
            'consulta_id' => $consulta->id,
            'total' => count($cedulas),
            'tiempo_estimado' => $this->formatearTiempo(count($cedulas) * self::SEGUNDOS_POR_CEDULA),
        ]);
    }

    /**
     * Endpoint de progreso: el frontend hace polling para ver el avance.
     */
    public function progreso(Consulta $consulta): JsonResponse
    {
        return response()->json([
            'id' => $consulta->id,
            'estado' => $consulta->estado,
            'total' => $consulta->total_cedulas,
            'procesadas' => $consulta->procesadas,
            'exitosas' => $consulta->exitosas,
            'fallidas' => $consulta->fallidas,
            'progreso' => $consulta->progreso,
            'archivo_salida' => $consulta->archivo_salida,
            'mensaje_error' => $consulta->mensaje_error,
            'created_at' => $consulta->created_at?->format('d/m/Y H:i'),
            'fecha_generacion' => $consulta->fecha_generacion?->format('d/m/Y H:i'),
        ]);
    }

    /**
     * Reprocesar una consulta que quedó atascada o falló.
     */
    public function reprocesar(Consulta $consulta): JsonResponse
    {
        // Solo reprocesar si está en error o atascada en procesando
        if (!in_array($consulta->estado, ['error', 'procesando'])) {
            return response()->json(['ok' => false, 'error' => 'Esta consulta no se puede reprocesar.']);
        }

        // Verificar que el archivo original exista
        if (!$consulta->archivo_entrada_path || !Storage::disk('public')->exists($consulta->archivo_entrada_path)) {
            return response()->json(['ok' => false, 'error' => 'El archivo original ya no existe.']);
        }

        // Re-leer cédulas del archivo original
        $import = new CedulasImport();
        Excel::import($import, Storage::disk('public')->path($consulta->archivo_entrada_path));
        $cedulas = $import->getCedulas();

        if (empty($cedulas)) {
            return response()->json(['ok' => false, 'error' => 'No se encontraron cédulas en el archivo.']);
        }

        // Reset contadores
        $consulta->update([
            'estado' => 'pendiente',
            'procesadas' => 0,
            'exitosas' => 0,
            'fallidas' => 0,
            'mensaje_error' => null,
            'archivo_salida' => null,
        ]);

        // Despachar job nuevamente
        \App\Jobs\ProcesarConsultaJob::dispatch($consulta->id, $cedulas);

        return response()->json([
            'ok' => true,
            'consulta_id' => $consulta->id,
            'total' => count($cedulas),
        ]);
    }

    public function descargarResultado(Consulta $consulta, Request $request)
    {
        if (!$consulta->archivo_salida || !Storage::disk('public')->exists($consulta->archivo_salida)) {
            return back()->with('error', 'Archivo no disponible.');
        }

        // Registrar fecha de descarga
        $consulta->update(['fecha_descarga' => now()]);

        $formato = $request->get('formato', 'xlsx');
        
        if ($formato === 'csv') {
            $rutaExcel = Storage::disk('public')->path($consulta->archivo_salida);
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($rutaExcel);
            
            $nombreCsv = 'resultados_adres_' . $consulta->id . '.csv';
            $rutaCsv = storage_path('app/temp_' . $nombreCsv);
            
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Csv($spreadsheet);
            $writer->setDelimiter(',');
            $writer->setEnclosure('"');
            $writer->save($rutaCsv);
            
            return response()->download($rutaCsv, $nombreCsv)->deleteFileAfterSend(true);
        }

        return Storage::disk('public')->download(
            $consulta->archivo_salida,
            'resultados_adres_' . $consulta->id . '.xlsx'
        );
    }

    public function descargarOriginal(Consulta $consulta)
    {
        if (!$consulta->archivo_entrada_path || !Storage::disk('public')->exists($consulta->archivo_entrada_path)) {
            return back()->with('error', 'Archivo original no disponible.');
        }

        return Storage::disk('public')->download(
            $consulta->archivo_entrada_path,
            $consulta->archivo_entrada
        );
    }

    public function eliminar(Consulta $consulta)
    {
        // Eliminar archivos
        if ($consulta->archivo_entrada_path && Storage::disk('public')->exists($consulta->archivo_entrada_path)) {
            Storage::disk('public')->delete($consulta->archivo_entrada_path);
        }
        if ($consulta->archivo_salida && Storage::disk('public')->exists($consulta->archivo_salida)) {
            Storage::disk('public')->delete($consulta->archivo_salida);
        }

        $consulta->delete();

        return redirect()->route('consultas.index')->with('success', 'Registro eliminado.');
    }

    /**
     * Vista dedicada para consultar cédula.
     */
    public function consultar()
    {
        return view('consultas.consultar');
    }

    /**
     * Buscar historial de una cédula específica.
     */
    public function buscarCedula(Request $request): JsonResponse
    {
        $request->validate([
            'cedula' => 'required|string|min:3|max:20',
        ]);

        $cedula = trim($request->input('cedula'));

        $resultados = Resultado::where('cedula', $cedula)
            ->orderBy('consultado_en', 'desc')
            ->get()
            ->map(function ($r) {
                return [
                    'id' => $r->id,
                    'consulta_id' => $r->consulta_id,
                    'cedula' => $r->cedula,
                    'tipo_documento' => $r->tipo_documento,
                    'nombres' => $r->nombres,
                    'apellidos' => $r->apellidos,
                    'fecha_nacimiento' => $r->fecha_nacimiento,
                    'departamento' => $r->departamento,
                    'municipio' => $r->municipio,
                    'estado_afiliacion' => $r->estado_afiliacion,
                    'entidad_eps' => $r->entidad_eps,
                    'regimen' => $r->regimen,
                    'fecha_afiliacion' => $r->fecha_afiliacion,
                    'fecha_finalizacion' => $r->fecha_finalizacion,
                    'tipo_afiliado' => $r->tipo_afiliado,
                    'error' => $r->error,
                    'exitosa' => $r->exitosa,
                    'consultado_en' => $r->consultado_en->format('d/m/Y H:i'),
                    'archivo_origen' => $r->consulta->archivo_entrada ?? '—',
                ];
            });

        return response()->json([
            'ok' => true,
            'cedula' => $cedula,
            'total_consultas' => $resultados->count(),
            'resultados' => $resultados,
        ]);
    }

    /**
     * Formatea segundos a texto legible (ej: "2 min 30 seg", "1 hora 5 min").
     */
    protected function formatearTiempo(int $segundos): string
    {
        if ($segundos < 60) {
            return $segundos . ' seg';
        }

        $horas = intdiv($segundos, 3600);
        $minutos = intdiv($segundos % 3600, 60);
        $segs = $segundos % 60;

        $partes = [];
        if ($horas > 0) $partes[] = $horas . ' hora' . ($horas > 1 ? 's' : '');
        if ($minutos > 0) $partes[] = $minutos . ' min';
        if ($segs > 0 && $horas === 0) $partes[] = $segs . ' seg';

        return implode(' ', $partes);
    }
}
