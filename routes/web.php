<?php

use Illuminate\Support\Facades\Route;
use Modules\Albaranes\Http\Controllers\AlbaranPageController;

// Mini-página del plugin (shell HTML; la autenticación real la hace la API por token).
Route::get('albaranes', [AlbaranPageController::class, 'index'])->name('albaranes.page');
