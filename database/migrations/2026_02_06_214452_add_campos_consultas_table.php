<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultas', function (Blueprint $table) {
            $table->string('archivo_entrada_path')->nullable()->after('archivo_entrada');
            $table->timestamp('fecha_generacion')->nullable()->after('estado');
            $table->timestamp('fecha_descarga')->nullable()->after('fecha_generacion');
        });
    }

    public function down(): void
    {
        Schema::table('consultas', function (Blueprint $table) {
            $table->dropColumn(['archivo_entrada_path', 'fecha_generacion', 'fecha_descarga']);
        });
    }
};
