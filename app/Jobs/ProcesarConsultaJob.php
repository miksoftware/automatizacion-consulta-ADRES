<?php

namespace App\Jobs;

use App\Exports\ResultadosExport;
use App\Models\Consulta;
use App\Models\Resultado;
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

    public int $timeout = 86400; // 24 horas máximo para archivos grandes
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
        $exitosas = 0;
        $fallidas = 0;

        try {
            $scraper = new AdresScraperService();

            foreach ($this->cedulas as $index => $cedula) {
                Log::info("Procesando cédula {$cedula} (" . ($index + 1) . "/" . count($this->cedulas) . ")");
                
                $resultado = $scraper->consultarCedula($cedula);
                $resultados[] = $resultado;

                $esExitosa = empty($resultado['error']);
                if ($esExitosa) {
                    $exitosas++;
                } else {
                    $fallidas++;
                }

                // Guardar resultado individual en BD
                Resultado::create([
                    'consulta_id' => $this->consultaId,
                    'cedula' => $resultado['cedula'] ?? $cedula,
                    'tipo_documento' => $resultado['tipo_documento'] ?? null,
                    'nombres' => $resultado['nombres'] ?? null,
                    'apellidos' => $resultado['apellidos'] ?? null,
                    'fecha_nacimiento' => $resultado['fecha_nacimiento'] ?? null,
                    'departamento' => $resultado['departamento'] ?? null,
                    'municipio' => $resultado['municipio'] ?? null,
                    'estado_afiliacion' => $resultado['estado'] ?? null,
                    'entidad_eps' => $resultado['entidad_eps'] ?? null,
                    'regimen' => $resultado['regimen'] ?? null,
                    'fecha_afiliacion' => $resultado['fecha_afiliacion'] ?? null,
                    'fecha_finalizacion' => $resultado['fecha_finalizacion'] ?? null,
                    'tipo_afiliado' => $resultado['tipo_afiliado'] ?? null,
                    'error' => $resultado['error'] ?? null,
                    'exitosa' => $esExitosa,
                    'consultado_en' => now(),
                ]);

                // Actualizar progreso en BD después de cada cédula
                $consulta->update([
                    'procesadas' => $index + 1,
                    'exitosas' => $exitosas,
                    'fallidas' => $fallidas,
                ]);

                // Delay entre consultas para no saturar al servidor de ADRES
                if ($index < count($this->cedulas) - 1) {
                    sleep(rand(2, 4));
                }
            }

            // Generar archivo de salida
            $nombreSalida = 'resultados_' . $this->consultaId . '_' . now()->format('Ymd_His') . '.xlsx';
            $rutaSalida = 'exports/' . $nombreSalida;
            
            Excel::store(new ResultadosExport($resultados), $rutaSalida, 'public');

            $consulta->update([
                'estado' => 'completado',
                'archivo_salida' => $rutaSalida,
                'fecha_generacion' => now(),
            ]);

            Log::info("Consulta {$this->consultaId} completada: {$exitosas} exitosas, {$fallidas} fallidas");

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

    /**
     * Manejar el fallo del job.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Job fallido para consulta {$this->consultaId}: " . $exception->getMessage());
        
        $consulta = Consulta::find($this->consultaId);
        if ($consulta) {
            $consulta->update([
                'estado' => 'error',
                'mensaje_error' => 'El proceso falló inesperadamente: ' . $exception->getMessage(),
            ]);
        }
    }
}
