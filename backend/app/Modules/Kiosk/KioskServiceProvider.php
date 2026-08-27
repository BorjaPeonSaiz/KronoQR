<?php

declare(strict_types=1);

namespace App\Modules\Kiosk;

use App\Http\RateLimiting\KioskRateLimit;
use App\Modules\Kiosk\Application\Port\DeviceFleet;
use App\Modules\Kiosk\Application\Port\KioskMetrics;
use App\Modules\Kiosk\Http\Policy\KioskPolicy;
use App\Modules\Kiosk\Infrastructure\Metrics\RedisKioskMetrics;
use App\Modules\Kiosk\Infrastructure\Persistence\DbDeviceFleet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Modulo Kiosk — dispositivos, emparejamiento, sincronizacion de lotes y
 * telemetria (doc 02 §1.6). Depende de Shared y de Attendance via caso de uso
 * publico: Kiosk usa el fichaje, no lo sirve.
 *
 * El quiosco nunca bloquea al empleado (regla dura 19): encola, confirma
 * localmente y genera una incidencia si algo no cuadra.
 *
 * **Los puertos que este modulo declara y sirve** (tarea 1.7):
 *
 *   - `DeviceFleet`  -> `Infrastructure/Persistence` (telemetria de `devices`)
 *   - `KioskMetrics` -> `Infrastructure/Metrics`
 *
 * **Los que necesita y no sirve** viven en `Shared/Application/Port` y los enlaza
 * el modulo dueño del dato (ADR-025, restriccion 3): `ClockingEmployees` en
 * `WorkforceServiceProvider`, `CredentialFingerprints` en
 * `IdentityServiceProvider` y `PersonalDataAccessLog` en
 * `ComplianceServiceProvider`. `Kiosk` no sabe quien le sirve ninguno de los tres,
 * que es el punto entero de la inversion.
 *
 * **Aqui no se registra ningun `Gate`, y no es un olvido de la regla dura 18.**
 * `KioskPolicy` se invoca por su nombre desde el `FormRequest` de cada endpoint
 * porque el pipeline de permisos exige que quien autoriza sea una cuenta con
 * roles, y el portador de un token de quiosco es su fila de `devices`. El motivo
 * completo esta en el docblock de {@see KioskPolicy}.
 */
final class KioskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DeviceFleet::class, DbDeviceFleet::class);

        // Singleton: no tiene estado y se resuelve en cada latido. En las pruebas
        // se sustituye por un doble que cuenta.
        $this->app->singleton(KioskMetrics::class, RedisKioskMetrics::class);
    }

    public function boot(): void
    {
        $this->registerTelemetryRateLimiter();
    }

    /**
     * La zona `kiosk` del §7.1: padron y latido, por dispositivo (RS-02).
     *
     * Una sola zona para los dos endpoints, al contrario que en el fichaje: los
     * dos son de bajo volumen y del mismo orden de magnitud —un latido por minuto,
     * un padron cada varias horas—, asi que separarlos daria dos contadores que
     * dirian lo mismo. Las dos zonas de `/scan` existen porque un lote trae
     * cincuenta escaneos y un escaneo suelto trae uno, que no es el caso aqui.
     *
     * Los limites de `/scan` y `/scan/batch` los registra
     * `AttendanceServiceProvider`, que es el modulo de esos endpoints.
     */
    private function registerTelemetryRateLimiter(): void
    {
        RateLimiter::for('kiosk', static fn (Request $request): array => KioskRateLimit::of(
            $request,
            'telemetry',
            Config::integer('kiosk.rate_limits.telemetry_per_device', 60),
            Config::integer('kiosk.rate_limits.per_ip', 600),
        ));
    }
}
