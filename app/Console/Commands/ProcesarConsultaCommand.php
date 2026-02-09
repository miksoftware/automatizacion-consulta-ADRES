<?php

namespace App\Console\Commands;

use App\Exports\ResultadosExport;
use App\Models\Consulta;
use App\Services\AdresScraperService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ProcesarConsultaCommand extends Command
{
    protected $signature = 'adres:procesar {consulta?}';
    protected $description = 'Procesa una consulta ADRES de forma síncrona';

    public function handle(): int
    {
        $consultaId = $this->argument('consulta');
        
        if ($consultaId) {
            $consulta = Consulta::find($consultaId);
        } else {
            $consulta = Consulta::where('estado', 'pendiente')->first();
        }

        if (!$consulta) {
            $this->error('No hay consultas pendientes.');
            return 1;
        }

        $this->info("Procesando consulta #{$consulta->id}: {$consulta->archivo_entrada}");
        
        // Leer cédulas del archivo
        $rutaArchivo = Storage::disk('public')->path('uploads/' . basename($consulta->archivo_entrada));
        
        if (!file_exists($rutaArchivo)) {
            $this->error("Archivo no encontrado: {$rutaArchivo}");
            return 1;
        }

        $import = new \App\Imports\CedulasImport();
        Excel::import($import, $rutaArchivo);
        $cedulas = $import->getCedulas();

        if (empty($cedulas)) {
            $this->error('No se encontraron cédulas en el archivo.');
            return 1;
        }

        $consulta->update([
            'estado' => 'procesando',
            'total_cedulas' => count($cedulas),
        ]);

        $this->info("Total de cédulas a procesar: " . count($cedulas));

        $scraper = null;
        $resultados = [];

        try {
            $scraper = new AdresScraperService();
            $bar = $this->output->createProgressBar(count($cedulas));
            $bar->start();

            foreach ($cedulas as $index => $cedula) {
                $resultado = $scraper->consultarCedula($cedula);
                $resultados[] = $resultado;

                $consulta->update([
                    'procesadas' => $index + 1,
                    'exitosas' => $consulta->exitosas + (empty($resultado['error']) ? 1 : 0),
                    'fallidas' => $consulta->fallidas + (!empty($resultado['error']) ? 1 : 0),
                ]);

                $bar->advance();

                if ($index < count($cedulas) - 1) {
                    sleep(rand(1, 2));
                }
            }

            $bar->finish();
            $this->newLine();

            // Generar archivo de salida
            $nombreSalida = 'resultados_' . $consulta->id . '_' . now()->format('Ymd_His') . '.xlsx';
            $rutaSalida = 'exports/' . $nombreSalida;
            
            Excel::store(new ResultadosExport($resultados), $rutaSalida, 'public');

            $consulta->update([
                'estado' => 'completado',
                'archivo_salida' => $rutaSalida,
            ]);

            $this->info("Consulta completada. Archivo: {$rutaSalida}");
            $this->info("Exitosas: {$consulta->exitosas}, Fallidas: {$consulta->fallidas}");

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            $consulta->update([
                'estado' => 'error',
                'mensaje_error' => $e->getMessage(),
            ]);
            return 1;
        } finally {
            if ($scraper) {
                $scraper->cerrar();
            }
        }

        return 0;
    }
}
