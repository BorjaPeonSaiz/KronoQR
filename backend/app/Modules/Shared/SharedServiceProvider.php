<?php

declare(strict_types=1);

namespace App\Modules\Shared;

use App\Modules\Shared\Application\Port\AuthenticationMetrics;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Application\Port\SealedPinOpener;
use App\Modules\Shared\Infrastructure\Adapter\CachePinAttempts;
use App\Modules\Shared\Infrastructure\Adapter\SodiumSealedPinOpener;
use App\Modules\Shared\Infrastructure\Adapter\SystemClock;
use App\Modules\Shared\Infrastructure\Metrics\RedisAuthenticationMetrics;
use Illuminate\Support\ServiceProvider;

/**
 * Modulo Shared — objetos de valor comunes, tipos base y contratos
 * transversales (doc 02 §1.6). No depende de ningun otro modulo.
 *
 * Enlaces pendientes de las tareas que los declaran:
 *   - CompliancePolicyProvider  -> Product/Infrastructure/Adapter (tarea 5.1, ADR-025)
 *   - OperationalSettingsProvider -> Product/Infrastructure/Adapter (tarea 5.1, ADR-025)
 * Los declara la tarea 1.1 y los enlaza ProductServiceProvider, que es quien
 * tiene las tablas: el enlace puerto->adaptador se declara siempre en el
 * ServiceProvider de quien implementa (ADR-025, restriccion 3).
 */
final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton y no bind: el reloj no tiene estado y se resuelve en cada
        // caso de uso. En las pruebas se sustituye por un reloj fijo.
        $this->app->singleton(Clock::class, SystemClock::class);

        // El bloqueo por intentos del PIN (RS-12). Vive aqui porque lo limpia
        // `Workforce` al restablecer (RF-ID-09) y lo incrementaran el quiosco
        // (RF-AT-11) y el portal (RF-ID-06): tres modulos que no pueden
        // importarse entre si necesitan el MISMO contador, o «restablecer
        // desbloquea» dependeria de por que puerta se este fallando.
        $this->app->singleton(PinAttempts::class, CachePinAttempts::class);

        // El sobre cerrado con el que el quiosco protege el PIN hasta que llega
        // al servidor (RF-AT-11, RL-12). Vive aqui, junto al contador de
        // intentos y al verificador, porque los tres son piezas del mismo PIN y
        // los tocan modulos que no pueden importarse entre si: el quiosco lo
        // abre al fichar (tarea 1.12) y `Kiosk` publica su clave publica en el
        // padron para que la tablet pueda cerrarlo sin red.
        $this->app->singleton(SealedPinOpener::class, SodiumSealedPinOpener::class);

        // `kronoqr_auth_attempts_total{channel,outcome}` (OWASP A09, §8.2). Vive
        // aqui, y no en `Identity`, porque los tres canales que lo alimentan
        // —panel, portal y PIN del quiosco— salen de tres modulos que no pueden
        // importarse entre si, y una metrica contada por tres adaptadores
        // distintos serian tres series con el mismo nombre y distinto criterio.
        $this->app->singleton(AuthenticationMetrics::class, RedisAuthenticationMetrics::class);
    }
}
