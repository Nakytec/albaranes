<?php

namespace Modules\Albaranes\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

/**
 * Sirve la mini-página del plugin. Es un shell HTML público: los datos se
 * cargan vía la API protegida con el token de Invoice Ninja, que el usuario
 * introduce en la propia página (se guarda sólo en su navegador).
 */
class AlbaranPageController extends Controller
{
    public function index(): View
    {
        return view('albaranes::albaranes');
    }
}
