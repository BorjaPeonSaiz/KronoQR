<?php

declare(strict_types=1);

namespace App\Modules\Shared;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Infrastructure\Adapter\CachePinAttempts;
use App\Modules\Shared\Infrastructure\Adapter\SystemClock;
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
    }
}
