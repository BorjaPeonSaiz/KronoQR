<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Configuracion transversal de la aplicacion que no pertenece a ningun modulo.
 *
 * Lo que sea de un modulo va en su {Modulo}ServiceProvider, no aqui: este
 * fichero es el sitio donde se acumula lo que nadie quiso ubicar, y por eso se
 * mantiene vacio mientras no haga falta.
 */
final class AppServiceProvider extends ServiceProvider {}
