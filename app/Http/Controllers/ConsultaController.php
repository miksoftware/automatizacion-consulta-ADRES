<?php

namespace App\Http\Controllers;

use App\Imports\CedulasImport;
use App\Exports\ResultadosExport;
use App\Models\Consulta;
use App\Services\AdresScraperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function procesar(Request $request): StreamedResponse
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
            return response()->stream(function() {
                echo "data: " . json_encode(['tipo' => 'error', 'error' => 'No se encontraron cédulas válidas']) . "\n\n";
                ob_flush();
                flush();
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        // Crear consulta
        $consulta = Consulta::create([
            'archivo_entrada' => $nombreOriginal,
            'archivo_entrada_path' => $ruta,
            'total_cedulas' => count($cedulas),
            'estado' => 'procesando',
        ]);

        return response()->stream(function() use ($cedulas, $consulta) {
            $scraper = null;
            $resultados = [];
            $tiempoInicio = microtime(true);

            try {
                // Enviar evento de inicio
                $this->enviarSSE([
                    'tipo' => 'inicio',
                    'total' => count($cedulas),
                    'tiempo_estimado' => $this->formatearTiempo(count($cedulas) * self::SEGUNDOS_POR_CEDULA),
                ]);

                $scraper = new AdresScraperService();
                $total = count($cedulas);
                $exitosas = 0;
                $fallidas = 0;

                foreach ($cedulas as $index => $cedula) {
                    $tiempoInicioCedula = microtime(true);

                    $resultado = $scraper->consultarCedula($cedula);
                    $resultados[] = $resultado;

                    $tiempoCedula = round(microtime(true) - $tiempoInicioCedula, 1);
                    $tiempoTotal = microtime(true) - $tiempoInicio;
                    $procesadas = $index + 1;
                    $progreso = round(($procesadas / $total) * 100);
                    $exito = empty($resultado['error']);

                    if ($exito) $exitosas++;
                    else $fallidas++;

                    // Calcular tiempo restante basado en promedio real
                    $promedioPorCedula = $tiempoTotal / $procesadas;
                    $restantes = $total - $procesadas;
                    $tiempoRestante = round($restantes * $promedioPorCedula);

                    $this->enviarSSE([
                        'tipo' => 'progreso',
                        'progreso' => $progreso,
                        'procesadas' => $procesadas,
                        'total' => $total,
                        'exitosas' => $exitosas,
                        'fallidas' => $fallidas,
                        'cedula' => $cedula,
                        'exito' => $exito,
                        'nombre' => $exito ? trim(($resultado['nombres'] ?? '') . ' ' . ($resultado['apellidos'] ?? '')) : null,
                        'eps' => $exito ? ($resultado['entidad_eps'] ?? '') : null,
                        'error_detalle' => !$exito ? ($resultado['error'] ?? 'Error desconocido') : null,
                        'tiempo_cedula' => $tiempoCedula,
                        'tiempo_transcurrido' => $this->formatearTiempo(round($tiempoTotal)),
                        'tiempo_restante' => $restantes > 0 ? $this->formatearTiempo($tiempoRestante) : 'Finalizando...',
                    ]);

                    // Espera breve entre consultas para no saturar ADRES
                    if ($index < $total - 1) {
                        sleep(1);
                    }
                }

                // Generar archivo de salida
                $nombreSalida = 'resultados_' . $consulta->id . '_' . now()->format('Ymd_His') . '.xlsx';
                $rutaSalida = 'exports/' . $nombreSalida;
                Excel::store(new ResultadosExport($resultados), $rutaSalida, 'public');

                $tiempoFinal = round(microtime(true) - $tiempoInicio);

                $consulta->update([
                    'estado' => 'completado',
                    'archivo_salida' => $rutaSalida,
                    'procesadas' => count($cedulas),
                    'exitosas' => $exitosas,
                    'fallidas' => $fallidas,
                    'fecha_generacion' => now(),
                ]);

                $this->enviarSSE([
                    'tipo' => 'completado',
                    'consulta_id' => $consulta->id,
                    'exitosas' => $exitosas,
                    'fallidas' => $fallidas,
                    'total' => $total,
                    'tiempo_total' => $this->formatearTiempo($tiempoFinal),
                ]);

            } catch (\Exception $e) {
                $consulta->update(['estado' => 'error', 'mensaje_error' => $e->getMessage()]);

                $this->enviarSSE([
                    'tipo' => 'error',
                    'error' => 'Error: ' . $e->getMessage(),
                ]);
            } finally {
                if ($scraper) {
                    $scraper->cerrar();
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
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
     * Envía un evento SSE al navegador.
     */
    protected function enviarSSE(array $data): void
    {
        echo "data: " . json_encode($data) . "\n\n";
        if (ob_get_level()) ob_flush();
        flush();
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
