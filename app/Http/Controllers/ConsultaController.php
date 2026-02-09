<?php

namespace App\Http\Controllers;

use App\Imports\CedulasImport;
use App\Exports\ResultadosExport;
use App\Models\Consulta;
use App\Services\AdresScraperService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConsultaController extends Controller
{
    public function index()
    {
        $consultas = Consulta::orderBy('created_at', 'desc')->paginate(15);
        return view('consultas.index', compact('consultas'));
    }

    public function procesar(Request $request): StreamedResponse
    {
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $archivo = $request->file('archivo');
        $nombreOriginal = $archivo->getClientOriginalName();
        $ruta = $archivo->store('uploads', 'public');

        // Leer cédulas
        $import = new CedulasImport();
        Excel::import($import, Storage::disk('public')->path($ruta));
        $cedulas = $import->getCedulas();

        if (empty($cedulas)) {
            Storage::disk('public')->delete($ruta);
            return response()->stream(function() {
                echo "data: " . json_encode(['error' => 'No se encontraron cédulas válidas']) . "\n\n";
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

            try {
                $scraper = new AdresScraperService();
                $total = count($cedulas);

                foreach ($cedulas as $index => $cedula) {
                    $resultado = $scraper->consultarCedula($cedula);
                    $resultados[] = $resultado;

                    $procesadas = $index + 1;
                    $progreso = round(($procesadas / $total) * 100);

                    echo "data: " . json_encode([
                        'progreso' => $progreso,
                        'procesadas' => $procesadas,
                        'total' => $total,
                        'cedula_actual' => $cedula,
                        'exito' => empty($resultado['error']),
                    ]) . "\n\n";
                    ob_flush();
                    flush();

                    if ($index < $total - 1) {
                        sleep(1);
                    }
                }

                // Guardar resultados
                $nombreSalida = 'resultados_' . $consulta->id . '_' . now()->format('Ymd_His') . '.xlsx';
                $rutaSalida = 'exports/' . $nombreSalida;
                Excel::store(new ResultadosExport($resultados), $rutaSalida, 'public');

                $consulta->update([
                    'estado' => 'completado',
                    'archivo_salida' => $rutaSalida,
                    'procesadas' => count($cedulas),
                    'exitosas' => count(array_filter($resultados, fn($r) => empty($r['error']))),
                    'fallidas' => count(array_filter($resultados, fn($r) => !empty($r['error']))),
                    'fecha_generacion' => now(),
                ]);

                echo "data: " . json_encode([
                    'completado' => true,
                    'consulta_id' => $consulta->id,
                    'mensaje' => '¡Archivo listo para descargar!',
                ]) . "\n\n";
                ob_flush();
                flush();

            } catch (\Exception $e) {
                $consulta->update(['estado' => 'error', 'mensaje_error' => $e->getMessage()]);
                
                echo "data: " . json_encode([
                    'error' => 'Error: ' . $e->getMessage(),
                ]) . "\n\n";
                ob_flush();
                flush();
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
}
