<?php

declare(strict_types=1);

namespace Tests\Support\Time;

use App\Modules\Shared\Application\Port\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Congela LOS DOS relojes de una prueba con framework en el mismo instante.
 *
 * Una prueba de Feature, Integration o Contract tiene dos relojes: el puerto
 * `Clock` que lee el dominio (ADR-021, regla dura 2) y el de Carbon, que leen
 * el framework y sus paquetes —Sanctum al comprobar `expires_at`, Eloquent al
 * rellenar `created_at`, el limitador de peticiones—. En produccion los dos son
 * el mismo reloj de pared. Si la prueba detiene solo el primero, fabrica una
 * situacion que el producto nunca vive, y el resultado pasa a depender del dia
 * en que se ejecuta.
 *
 * Asi se puso `main` en rojo el 13-09-2026 sin que nadie tocara nada:
 * `PlanLimitsDoNotBlockTest` emitia un token de quiosco con el dominio
 * detenido en junio (caducidad = junio + 90 dias) y fichaba con el; Sanctum
 * comparaba esa caducidad con el reloj real y, pasado septiembre, respondia
 * 401. La prueba llevaba tres meses en verde por calendario, no por diseño.
 *
 * Por eso el reloj del contenedor nunca se instala a mano en esas suites
 * (`FrozenTimeTest` lo exige): se pasa por aqui, que ademas detiene Carbon en
 * el mismo instante. El `TestCase` de Laravel devuelve Carbon al reloj real al
 * terminar cada prueba; el contenedor se reconstruye entero.
 *
 * Las pruebas unitarias no pasan por aqui: no arrancan el framework y su unico
 * reloj es el `FixedClock` que inyectan a mano.
 */
final class FrozenTime
{
    /**
     * `$wallClock` se interpreta en UTC, como en {@see FixedClock::at()}.
     *
     * Devuelve el `FixedClock` instalado para quien ademas quiera pasarlo a
     * mano a un servicio construido fuera del contenedor.
     */
    public static function at(string $wallClock): FixedClock
    {
        return self::install(FixedClock::at($wallClock));
    }

    public static function install(FixedClock $clock): FixedClock
    {
        app()->instance(Clock::class, $clock);

        // Carbon 3 comparte el instante de prueba entre la clase mutable y la
        // inmutable; se fijan las dos para no depender de ese detalle.
        Carbon::setTestNow($clock->now());
        CarbonImmutable::setTestNow($clock->now());

        return $clock;
    }
}
