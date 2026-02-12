<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resultados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulta_id')->constrained('consultas')->onDelete('cascade');
            $table->string('cedula', 20)->index();
            $table->string('tipo_documento', 20)->nullable();
            $table->string('nombres')->nullable();
            $table->string('apellidos')->nullable();
            $table->string('fecha_nacimiento', 30)->nullable();
            $table->string('departamento')->nullable();
            $table->string('municipio')->nullable();
            $table->string('estado_afiliacion', 50)->nullable();
            $table->string('entidad_eps')->nullable();
            $table->string('regimen', 50)->nullable();
            $table->string('fecha_afiliacion', 30)->nullable();
            $table->string('fecha_finalizacion', 30)->nullable();
            $table->string('tipo_afiliado', 50)->nullable();
            $table->text('error')->nullable();
            $table->boolean('exitosa')->default(false);
            $table->timestamp('consultado_en')->useCurrent();
            $table->timestamps();

            // Índice compuesto para búsquedas frecuentes
            $table->index(['cedula', 'consultado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resultados');
    }
};
