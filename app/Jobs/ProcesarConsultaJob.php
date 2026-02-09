<?php

namespace App\Jobs;

use App\Exports\ResultadosExport;
use App\Models\Consulta;
use App\Services\AdresScraperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ProcesarConsultaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600; // 1 hora máximo
    public int $tries = 1;

    public function __construct(
        protected int $consultaId,
        protected array $cedulas
    ) {}

    public function handle(): void
    {
        $consulta = Consulta::find($this->consultaId);
        
        if (!$consulta) {
            Log::error("Consulta {$this->consultaId} no encontrada");
            return;
        }

        $consulta->update(['estado' => 'procesando']);
        
        $scraper = null;
        $resultados = [];

        try {
            $scraper = new AdresScraperService();

            foreach ($this->cedulas as $index => $cedula) {
                Log::info("Procesando cédula {$cedula} (" . ($index + 1) . "/" . count($this->cedulas) . ")");
                
                $resultado = $scraper->consultarCedula($cedula);
                $resultados[] = $resultado;

                $consulta->update([
                    'procesadas' => $index + 1,
                    'exitosas' => $consulta->exitosas + (empty($resultado['error']) ? 1 : 0),
                    'fallidas' => $consulta->fallidas + (!empty($resultado['error']) ? 1 : 0),
                ]);

                // Delay entre consultas para no saturar el servidor
                if ($index < count($this->cedulas) - 1) {
                    sleep(rand(1, 2));
                }
            }

            // Generar archivo de salida
            $nombreSalida = 'resultados_' . $this->consultaId . '_' . now()->format('Ymd_His') . '.xlsx';
            $rutaSalida = 'exports/' . $nombreSalida;
            
            Excel::store(new ResultadosExport($resultados), $rutaSalida, 'public');

            $consulta->update([
                'estado' => 'completado',
                'archivo_salida' => $rutaSalida,
            ]);

            Log::info("Consulta {$this->consultaId} completada exitosamente");

        } catch (\Exception $e) {
            Log::error("Error procesando consulta {$this->consultaId}: " . $e->getMessage());
            
            $consulta->update([
                'estado' => 'error',
                'mensaje_error' => $e->getMessage(),
            ]);
        } finally {
            if ($scraper) {
                $scraper->cerrar();
            }
        }
    }
}
