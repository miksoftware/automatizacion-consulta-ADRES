<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consulta extends Model
{
    protected $fillable = [
        'archivo_entrada',
        'archivo_entrada_path',
        'archivo_salida',
        'total_cedulas',
        'procesadas',
        'exitosas',
        'fallidas',
        'estado',
        'fecha_generacion',
        'fecha_descarga',
        'mensaje_error',
    ];

    protected $casts = [
        'total_cedulas' => 'integer',
        'procesadas' => 'integer',
        'exitosas' => 'integer',
        'fallidas' => 'integer',
        'fecha_generacion' => 'datetime',
        'fecha_descarga' => 'datetime',
    ];

    public function getProgresoAttribute(): int
    {
        return $this->total_cedulas > 0 
            ? round(($this->procesadas / $this->total_cedulas) * 100) 
            : 0;
    }
}
