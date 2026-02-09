<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultas', function (Blueprint $table) {
            $table->id();
            $table->string('archivo_entrada');
            $table->string('archivo_salida')->nullable();
            $table->integer('total_cedulas')->default(0);
            $table->integer('procesadas')->default(0);
            $table->integer('exitosas')->default(0);
            $table->integer('fallidas')->default(0);
            $table->enum('estado', ['pendiente', 'procesando', 'completado', 'error'])->default('pendiente');
            $table->text('mensaje_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultas');
    }
};
