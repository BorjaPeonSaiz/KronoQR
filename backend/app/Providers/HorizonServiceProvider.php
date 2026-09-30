<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Cierra el panel de Horizon y sus 21 rutas `horizon/api/*` a TODO el mundo
 * (regla dura 18, hallazgo T1 de la verificacion de la 2.1.0).
 *
 * **El panel no forma parte del producto.** Los administradores del cliente no
 * lo usan —el estado de las colas se ve por las metricas de `/metrics` y las
 * alertas de `infra/observability`—, y lo que expone son las cargas de los
 * trabajos, que llevan datos personales de la plantilla. Por eso el gate no
 * tiene lista de correos ni rol que lo abra: devuelve `false` siempre.
 *
 * **Se sobrescribe `authorization()` y no solo `gate()`**: la del paquete anade
 * `|| app()->environment('local')`, y sin este proveedor el comportamiento por
 * defecto de `Horizon::check()` es exactamente ese. Una instalacion arrancada
 * por error con `APP_ENV=local` dejaba el panel abierto sin sesion. Aqui el
 * entorno no participa: cerrado en `local`, en `testing` y en `production`.
 *
 * Si algun dia hiciera falta abrirlo para diagnostico, es una decision de
 * producto con su ADR y su asiento de auditoria, no una linea en este gate.
 * Lo verifica `tests/Feature/Http/HorizonDashboardClosedTest.php`.
 */
final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(static fn (Request $request): bool => Gate::check('viewHorizon', [$request->user()]));
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', static fn (mixed $user = null): bool => false);
    }
}
