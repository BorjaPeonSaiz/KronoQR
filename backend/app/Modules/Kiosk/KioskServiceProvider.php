<?php

declare(strict_types=1);

namespace App\Modules\Kiosk;

use App\Http\RateLimiting\KioskRateLimit;
use App\Modules\Identity\Application\UseCase\IssueDeviceToken;
use App\Modules\Kiosk\Application\Port\DeviceFleet;
use App\Modules\Kiosk\Application\Port\DeviceRegistry;
use App\Modules\Kiosk\Application\Port\KioskEventPublisher;
use App\Modules\Kiosk\Application\Port\KioskMetrics;
use App\Modules\Kiosk\Application\Port\PairingRequests;
use App\Modules\Kiosk\Application\Port\PairingSecrets;
use App\Modules\Kiosk\Application\UseCase\CheckKioskHealth;
use App\Modules\Kiosk\Application\UseCase\ClaimPairing;
use App\Modules\Kiosk\Application\UseCase\RequestPairing;
use App\Modules\Kiosk\Domain\Model\PairingRequest;
use App\Modules\Kiosk\Domain\ValueObject\DeviceSummary;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthThresholds;
use App\Modules\Kiosk\Http\Policy\KioskPairingPolicy;
use App\Modules\Kiosk\Http\Policy\KioskPolicy;
use App\Modules\Kiosk\Infrastructure\Adapter\LaravelKioskEventPublisher;
use App\Modules\Kiosk\Infrastructure\Adapter\RandomPairingSecrets;
use App\Modules\Kiosk\Infrastructure\Console\KioskHealthCommand;
use App\Modules\Kiosk\Infrastructure\Console\PairingCodeCommand;
use App\Modules\Kiosk\Infrastructure\Metrics\RedisKioskMetrics;
use App\Modules\Kiosk\Infrastructure\Persistence\DbDeviceFleet;
use App\Modules\Kiosk\Infrastructure\Persistence\DbDeviceRegistry;
use App\Modules\Kiosk\Infrastructure\Persistence\DbPairingRequests;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Support\ConstantTimeFloor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Modulo Kiosk — dispositivos, emparejamiento, sincronizacion de lotes y
 * telemetria (doc 02 §1.6). Depende de Shared, de Attendance y de Identity via
 * caso de uso publico: Kiosk usa el fichaje y la emision de tokens, no los sirve.
 *
 * El quiosco nunca bloquea al empleado (regla dura 19): encola, confirma
 * localmente y genera una incidencia si algo no cuadra.
 *
 * **Los puertos que este modulo declara y sirve**:
 *
 *   - `DeviceFleet`         -> `Infrastructure/Persistence` (telemetria de `devices`)
 *   - `KioskMetrics`        -> `Infrastructure/Metrics`
 *   - `DeviceRegistry`      -> `Infrastructure/Persistence` (alta y lista, tarea 5.6)
 *   - `PairingRequests`     -> `Infrastructure/Persistence` (tarea 5.6)
 *   - `PairingSecrets`      -> `Infrastructure/Adapter` (CSPRNG y hash, tarea 5.6)
 *   - `KioskEventPublisher` -> `Infrastructure/Adapter` (tarea 5.6)
 *
 * **Los que necesita y no sirve** viven en `Shared/Application/Port` y los enlaza
 * el modulo dueño del dato (ADR-025, restriccion 3): `ClockingEmployees` en
 * `WorkforceServiceProvider`, `CredentialFingerprints` en
 * `IdentityServiceProvider`, `InstallationSiteProvider` en `Workforce` y
 * `PersonalDataAccessLog` en `ComplianceServiceProvider`.
 *
 * **La emision y la revocacion del token de un dispositivo NO entran por un
 * puerto** (tarea 5.6): `ClaimPairing` y `UnpairDevice` llaman directamente a
 * `Identity\Application\UseCase\IssueDeviceToken` y `RevokeDeviceToken`. El doc 01
 * §5.5 dice que esa capacidad «se expone a `Kiosk` por caso de uso publico
 * explicito» y el §1.6 concede la arista; un puerto invertido añadiria una
 * interfaz, un adaptador y un `bind` para envolver una llamada ya autorizada.
 *
 * **Dos policies y se invocan de forma distinta, que no es un descuido.**
 * {@see KioskPolicy} autoriza a un **dispositivo** —su `tokenable` es una fila de
 * `devices`, que no implementa `Authorizable`— y por eso se llama por su nombre
 * desde el `FormRequest`. {@see KioskPairingPolicy} autoriza a una **persona** del
 * panel y se registra en el `Gate`, como el resto de las policies de gestion.
 */
final class KioskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DeviceFleet::class, DbDeviceFleet::class);
        $this->app->bind(DeviceRegistry::class, DbDeviceRegistry::class);
        $this->app->bind(PairingRequests::class, DbPairingRequests::class);
        $this->app->bind(PairingSecrets::class, RandomPairingSecrets::class);
        $this->app->bind(KioskEventPublisher::class, LaravelKioskEventPublisher::class);

        // Singleton: no tiene estado y se resuelve en cada latido. En las pruebas
        // se sustituye por un doble que cuenta.
        $this->app->singleton(KioskMetrics::class, RedisKioskMetrics::class);

        $this->registerPairingUseCases();
        $this->registerHealthUseCase();
    }

    public function boot(): void
    {
        $this->registerTelemetryRateLimiter();
        $this->registerPairingRateLimiters();
        $this->registerPairingPolicies();

        if ($this->app->runningInConsole()) {
            /*
             * La via alternativa del Anexo C (RF-PD-06).
             *
             * **NO va en `routes/console.php`**: ahi vive cuando se ejecuta cada
             * comando programado, y este no se programa. Lo ejecuta una persona
             * cuando el panel no esta accesible, que es el unico motivo por el que
             * existe.
             */
            /*
             * `kiosk:health` va por la misma puerta y por el mismo motivo: lo
             * ejecuta una persona cuando quiere ver sus quioscos sin abrir el
             * panel —o cuando no puede abrirlo— y no se programa. Es de solo
             * lectura (RF-PA-07, doc 02 Anexo C).
             */
            $this->commands([PairingCodeCommand::class, KioskHealthCommand::class]);
        }
    }

    /**
     * Los dos casos de uso que reciben configuracion **ya resuelta**.
     *
     * Es la misma regla que con los umbrales legales (regla dura 14) aplicada a la
     * configuracion en general: un caso de uso que consulta `config()` no se puede
     * probar con dos valores sin tocar la configuracion global, y ademas
     * `Application` no usa facades (§3.5, verificado por Deptrac).
     */
    private function registerPairingUseCases(): void
    {
        $this->app->bind(RequestPairing::class, static fn ($app): RequestPairing => new RequestPairing(
            $app->make(PairingRequests::class),
            $app->make(PairingSecrets::class),
            $app->make(KioskMetrics::class),
            $app->make(LoggerInterface::class),
            $app->make(Clock::class),
            Config::integer('kiosk.pairing.code_ttl_seconds', 600),
            Config::integer('kiosk.pairing.poll_interval_seconds', 5),
            Config::integer('kiosk.pairing.purge_after_hours', 24),
            Config::integer('kiosk.pairing.max_live_pending', 20),
        ));

        $this->app->bind(ClaimPairing::class, static fn ($app): ClaimPairing => new ClaimPairing(
            $app->make(PairingRequests::class),
            $app->make(DeviceRegistry::class),
            $app->make(PairingSecrets::class),
            $app->make(IssueDeviceToken::class),
            $app->make(KioskMetrics::class),
            $app->make(LoggerInterface::class),
            $app->make(Clock::class),
            // El MISMO suelo que usa la resolucion de credenciales del fichaje
            // (`security.rejection_floor_ms`): los dos caminos tienen la misma
            // obligacion de RS-03 y ya no pueden divergir.
            $app->make(ConstantTimeFloor::class),
        ));
    }

    /**
     * `kiosk:health` con sus dos plazos **ya resueltos** (RF-PA-07, tarea 5.11).
     *
     * Misma regla que los dos casos de uso del emparejamiento: la configuracion
     * se resuelve en la raiz de composicion y no dentro del caso de uso, que asi
     * se prueba con dos valores sin tocar la configuracion global y no necesita
     * facades (§3.5, verificado por Deptrac).
     *
     * **Los ordena antes de construir el objeto de valor**, y no es celo: los dos
     * numeros salen del `.env` de un cliente. {@see KioskHealthThresholds} exige
     * que el plazo de silencio vaya despues del de latido fresco —si no, no
     * habria zona de aviso—, y un `.env` con los dos cruzados dejaria sin
     * diagnostico justo a quien lo ejecuta porque algo va mal. Se ordena, se
     * diagnostica, y el numero raro se ve en el `--json`.
     */
    private function registerHealthUseCase(): void
    {
        $this->app->bind(CheckKioskHealth::class, static function ($app): CheckKioskHealth {
            $fresh = max(1, Config::integer('kiosk.health.fresh_within_seconds', 120));
            $silent = max($fresh + 1, Config::integer('kiosk.health.silent_after_seconds', 600));

            return new CheckKioskHealth(
                $app->make(DeviceRegistry::class),
                $app->make(Clock::class),
                new KioskHealthThresholds($fresh, $silent),
            );
        });
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

    /**
     * Las dos zonas del emparejamiento (tarea 5.6, RF-PD-06, §7.1).
     *
     * **DOS Y NO UNA, y con claves distintas**, que es lo que las hace servir para
     * algo:
     *
     * - `pairing-request` cuenta **por IP**. Es lo unico que hay: quien pide un
     *   codigo no tiene token ni identidad. Sin techo, cualquiera puede llenar la
     *   tabla de solicitudes pendientes y agotar el espacio de codigos de seis
     *   digitos, que es la unica forma de negar el alta de un quiosco desde fuera.
     *
     * - `pairing-claim` cuenta **por `pairing_id` del cuerpo**, ademas del techo
     *   por IP. Un limite solo por IP no acota nada cuando todos los quioscos de
     *   un hotel salen por la misma direccion, y este es el endpoint donde se
     *   intentaria adivinar un secreto.
     *
     * **La clave del `claim` sale del cuerpo, y aqui eso es correcto** aunque
     * {@see KioskRateLimit} advierta de lo contrario para el fichaje. Alli la
     * clave por dispositivo tiene que salir del token porque, si viniera del
     * cuerpo, quien quisiera saltarse su cuota solo tendria que cambiar el numero.
     * Aqui **no hay token todavia** y cambiar el `pairing_id` no sirve de nada:
     * cada solicitud tiene su propio secreto, asi que sondear con otro
     * identificador no acerca a nadie a ningun token. El techo por IP —el general
     * del quiosco, 600— sigue puesto para el caso en que alguien genere
     * identificadores en masa.
     *
     * **El techo del `claim` es holgado a proposito**: seis veces la cadencia de
     * sondeo. La regla dura 19 dice que la tablet no puede quedarse atrapada, y un
     * `429` en el ultimo sondeo de un emparejamiento ya confirmado seria
     * exactamente eso.
     */
    private function registerPairingRateLimiters(): void
    {
        RateLimiter::for('pairing-request', static fn (Request $request): array => [
            Limit::perMinute(max(1, Config::integer('kiosk.pairing.request_rate_per_ip', 10)))
                ->by('pairing-request:ip:'.((string) $request->ip())),
        ]);

        RateLimiter::for('pairing-claim', static function (Request $request): array {
            $pairingId = $request->input('pairing_id');

            return [
                Limit::perMinute(max(1, Config::integer('kiosk.pairing.claim_rate_per_pairing', 30)))
                    ->by('pairing-claim:id:'.(is_string($pairingId) ? $pairingId : 'anonymous')),
                Limit::perMinute(max(1, Config::integer('kiosk.rate_limits.per_ip', 600)))
                    ->by('pairing-claim:ip:'.((string) $request->ip())),
            ];
        });
    }

    /**
     * Las tres rutas de gestion del emparejamiento, de `admin` y de nadie mas
     * (§7.3 nota 5, regla dura 18).
     *
     * **Dos sujetos y una sola policy**, porque son dos cosas distintas sobre las
     * que se autoriza: {@see PairingRequest} es «una solicitud de emparejamiento de
     * esta instalacion» —cuando se autoriza el `confirm` todavia no se sabe cual
     * es, ni si existe— y {@see DeviceSummary} es «la flota de quioscos». Ninguno
     * de los dos es una fila concreta: la policy no autoriza sobre un dispositivo
     * en particular, y no hay ninguna operacion por fila que pudiera necesitar la
     * otra.
     */
    private function registerPairingPolicies(): void
    {
        Gate::policy(PairingRequest::class, KioskPairingPolicy::class);
        Gate::policy(DeviceSummary::class, KioskPairingPolicy::class);
    }
}
