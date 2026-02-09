<?php

use App\Http\Controllers\ConsultaController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ConsultaController::class, 'index'])->name('consultas.index');
Route::post('/procesar', [ConsultaController::class, 'procesar'])->name('consultas.procesar');
Route::get('/descargar/{consulta}/resultado', [ConsultaController::class, 'descargarResultado'])->name('consultas.descargar.resultado');
Route::get('/descargar/{consulta}/original', [ConsultaController::class, 'descargarOriginal'])->name('consultas.descargar.original');
Route::delete('/consultas/{consulta}', [ConsultaController::class, 'eliminar'])->name('consultas.eliminar');
