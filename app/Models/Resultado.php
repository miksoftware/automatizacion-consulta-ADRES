<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resultado extends Model
{
    protected $table = 'clientes';

    protected $fillable = [
        'consulta_id',
        'cedula',
        'tipo_documento',
        'nombres',
        'apellidos',
        'fecha_nacimiento',
        'departamento',
        'municipio',
        'estado_afiliacion',
        'entidad_eps',
        'regimen',
        'fecha_afiliacion',
        'fecha_finalizacion',
        'tipo_afiliado',
        'error',
        'exitosa',
        'consultado_en',
    ];

    protected $casts = [
        'exitosa' => 'boolean',
        'consultado_en' => 'datetime',
    ];

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(Consulta::class);
    }
}
