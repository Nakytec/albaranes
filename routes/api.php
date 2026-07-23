<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Modules\Albaranes\Http\Controllers\AlbaranApiController;

// Mismo grupo de middleware que la API v1 del core (token X-API-TOKEN).
// SubstituteBindings es necesario para el binding implícito de modelos
// ({client}/{quote}); las rutas cargadas por el módulo no lo heredan del grupo 'api'.
Route::group(['middleware' => ['throttle:api', 'token_auth', 'valid_json', 'locale', SubstituteBindings::class], 'prefix' => 'api/v1/albaranes', 'as' => 'api.albaranes.'], function () {
    Route::get('clients/{client}', [AlbaranApiController::class, 'index'])->name('index');
    Route::get('clients/{client}/candidates', [AlbaranApiController::class, 'candidates'])->name('candidates');
    Route::post('clients/{client}/consolidate', [AlbaranApiController::class, 'consolidate'])->name('consolidate');
    Route::put('quotes/{quote}/toggle', [AlbaranApiController::class, 'toggle'])->name('toggle');
    Route::get('quotes/{quote}/pdf', [AlbaranApiController::class, 'pdf'])->name('pdf');
    Route::post('quotes/{quote}/email', [AlbaranApiController::class, 'email'])->name('email');
});
