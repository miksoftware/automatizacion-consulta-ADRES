<?php

use App\Http\Controllers\Api\ConsultaCedulaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - ADRES
|--------------------------------------------------------------------------
|
| Endpoints protegidos por token (header: Authorization: Bearer <token>).
| Pensados para ser consumidos por el sistema externo unificado.
|
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/consulta/cedula/{cedula}', [ConsultaCedulaController::class, 'show'])
        ->where('cedula', '[0-9]+')
        ->name('api.consulta.cedula');
});
