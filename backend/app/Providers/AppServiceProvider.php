<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Environment\ProductionSafetyGuard;
use Illuminate\Support\ServiceProvider;

/**
 * Configuracion transversal de la aplicacion que no pertenece a ningun modulo.
 *
 * Lo que sea de un modulo va en su {Modulo}ServiceProvider, no aqui: este
 * fichero es el sitio donde se acumula lo que nadie quiso ubicar, y por eso se
 * mantiene lo mas vacio posible.
 *
 * Lo unico que vive aqui es la guarda de arranque de produccion, que no es de
 * ningun modulo porque no es del producto: es del DESPLIEGUE.
 */
final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Lo primero, y antes de que exista ninguna peticion. Ver el porque en
        // el docblock de ProductionSafetyGuard: con las trazas encendidas en
        // produccion, un error cualquiera publica las claves de la instalacion.
        ProductionSafetyGuard::assert(
            (string) $this->app->environment(),
            (bool) config('app.debug'),
        );
    }
}
