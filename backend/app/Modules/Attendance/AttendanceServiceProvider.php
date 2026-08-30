<?php

declare(strict_types=1);

namespace App\Modules\Attendance;

use App\Http\RateLimiting\KioskRateLimit;
use App\Modules\Attendance\Application\Port\CorrectionMetrics;
use App\Modules\Attendance\Application\Port\DailyTotalsProjection;
use App\Modules\Attendance\Application\Port\EventPublisher;
use App\Modules\Attendance\Application\Port\FlaggedScans;
use App\Modules\Attendance\Application\Port\ProjectionMetrics;
use App\Modules\Attendance\Application\Port\ScanLog;
use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Attendance\Application\Port\ShiftCorrectionLedger;
use App\Modules\Attendance\Application\Port\ShiftEntryHistory;
use App\Modules\Attendance\Application\Port\ShiftEntrySubject;
use App\Modules\Attendance\Application\Port\WorkDayLedger;
use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Event\DailyTotalsRecalculated;
use App\Modules\Attendance\Http\Policy\ScanPolicy;
use App\Modules\Attendance\Http\Policy\ShiftEntryPolicy;
use App\Modules\Attendance\Infrastructure\Adapter\LaravelEventBus;
use App\Modules\Attendance\Infrastructure\Console\DetectIncidentsCommand;
use App\Modules\Attendance\Infrastructure\Console\ReconcileProjectionsCommand;
use App\Modules\Attendance\Infrastructure\Metrics\RedisCorrectionMetrics;
use App\Modules\Attendance\Infrastructure\Metrics\RedisScanMetrics;
use App\Modules\Attendance\Infrastructure\Metrics\TextfileProjectionMetrics;
use App\Modules\Attendance\Infrastructure\Persistence\DatabaseShiftCorrectionLedger;
use App\Modules\Attendance\Infrastructure\Persistence\EloquentFlaggedScans;
use App\Modules\Attendance\Infrastructure\Persistence\EloquentScanLog;
use App\Modules\Attendance\Infrastructure\Persistence\EloquentShiftEntryHistory;
use App\Modules\Attendance\Infrastructure\Persistence\EloquentShiftEntrySubject;
use App\Modules\Attendance\Infrastructure\Persistence\EloquentWorkDayLedger;
use App\Modules\Attendance\Infrastructure\Persistence\EloquentWorkDayRepository;
use App\Modules\Attendance\Infrastructure\Persistence\ShiftEntry;
use App\Modules\Attendance\Infrastructure\Projection\DailyTotalsProjector;
use App\Modules\Attendance\Infrastructure\Projection\DatabaseDailyTotalsProjection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Modulo Attendance — nucleo del producto: fichajes, tramos, jornadas y
 * correcciones (doc 02 §1.6). Solo puede depender de Shared.
 *
 * El nucleo declara sus puertos y no nombra a quien los sirve (ADR-025). Aqui se
 * enlazan **solo los que implementa el propio modulo**:
 *
 *   - `WorkDayRepository` -> `Infrastructure/Persistence`
 *   - `ScanLog`           -> `Infrastructure/Persistence`
 *   - `EventPublisher`    -> `Infrastructure/Adapter`
 *   - `ScanMetrics`       -> `Infrastructure/Metrics`
 *
 * Los que sirve un satelite los enlaza el satelite, no este proveedor:
 * `CredentialResolver` en `IdentityServiceProvider` (tarea 1.5);
 * `EmployeeDirectory` y `SiteCalendar` en `WorkforceServiceProvider`;
 * `Clock` en `SharedServiceProvider`; `OperationalSettingsProvider` en
 * `ProductServiceProvider`. **`Attendance` no sabe quien le resuelve una
 * credencial**, que es el punto entero de ADR-025.
 *
 * **De las dos policies del modulo, solo una entra por el `Gate`, y no es un
 * olvido de la regla dura 18.** `ShiftEntryPolicy` si, porque quien corrige es
 * una cuenta de `users` con roles. `ScanPolicy` no: se invoca por su nombre
 * desde el `FormRequest` porque el pipeline de permisos exige que quien autoriza
 * sea una cuenta con roles, y el portador de un token de quiosco es su fila de
 * `devices`. El motivo completo esta en el docblock de {@see ScanPolicy}.
 */
final class AttendanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WorkDayRepository::class, EloquentWorkDayRepository::class);
        $this->app->bind(ScanLog::class, EloquentScanLog::class);
        $this->app->bind(EventPublisher::class, LaravelEventBus::class);

        // Correcciones (tarea 1.15). Las dos las sirve este mismo modulo:
        // `shift_corrections` es parte del registro horario —se consulta al
        // pintar una jornada y se exporta con el registro legal—, no auditoria
        // de otro modulo.
        $this->app->bind(ShiftCorrectionLedger::class, DatabaseShiftCorrectionLedger::class);
        $this->app->bind(ShiftEntryHistory::class, EloquentShiftEntryHistory::class);

        /*
         * La revision diaria (RF-PR-01, tarea 2.6). Dos puertos de **solo
         * lectura**, y que lo sean es la garantia estructural de RN-08: el
         * detector no tiene por donde cerrar un tramo aunque alguien quisiera.
         *
         * `WorkDayLedger` es un puerto aparte de `WorkDayRepository` y no dos
         * metodos mas dentro de el: aquel sirve al fichaje —carga una jornada, la
         * guarda y traduce las violaciones de RN-01 y RN-02— y este recorre a
         * toda la plantilla sin escribir nunca.
         */
        $this->app->bind(WorkDayLedger::class, EloquentWorkDayLedger::class);
        $this->app->bind(FlaggedScans::class, EloquentFlaggedScans::class);

        // De quien es un tramo, para autorizar la correccion antes de ejecutarla
        // (RF-ID-03). Puerto propio y no un metodo mas del anterior: aquel existe
        // para elegir entre 404 y 409 y lo dice de si mismo.
        $this->app->bind(ShiftEntrySubject::class, EloquentShiftEntrySubject::class);

        /*
         * La reconciliacion nocturna (RF-PR-02, ADR-007, tarea 2.7).
         *
         * `DailyTotalsProjection` lee la proyeccion y **no la escribe**: la
         * escritura sigue teniendo un solo camino, el listener
         * `DailyTotalsProjector`. Que el puerto de lectura sea otro es lo que
         * impide que la reconciliacion se invente una aritmetica propia con la
         * que «arreglar» filas — compararia entonces dos calculos distintos.
         */
        $this->app->bind(DailyTotalsProjection::class, DatabaseDailyTotalsProjection::class);

        // Singleton: no tiene estado y se resuelve en cada peticion de escaneo.
        // En las pruebas se sustituye por un doble que cuenta.
        $this->app->singleton(ScanMetrics::class, RedisScanMetrics::class);
        $this->app->singleton(CorrectionMetrics::class, RedisCorrectionMetrics::class);

        /*
         * `projection_divergence_total` sobre el colector textfile y no sobre
         * Redis, al contrario que las dos de arriba (doc 02 §8.2).
         *
         * El criterio es el mismo que separa a `TextfileAuditMetrics` de
         * `RedisScanMetrics`: aquellas cuentan un hecho que ocurre cincuenta
         * veces por segundo y esta cuenta uno que **no debe ocurrir nunca**. Un
         * contador que tiene que permanecer en cero para siempre no puede vivir
         * en una cache que un despliegue puede vaciar: un `FLUSHALL` devolveria
         * la serie a cero, que es justo el valor que significa «todo esta bien».
         */
        $this->app->singleton(ProjectionMetrics::class, TextfileProjectionMetrics::class);
    }

    public function boot(): void
    {
        $this->registerScanRateLimiters();

        /*
         * La policy de las correcciones (RF-PA-04, regla dura 18).
         *
         * Esta SI entra por el `Gate`, al contrario que `ScanPolicy`: quien
         * autoriza aqui es una cuenta de `users` con roles, que es exactamente
         * lo que el pipeline de permisos espera. La de escaneo no puede porque
         * el portador de un token de quiosco es su fila de `devices` (ver su
         * docblock).
         *
         * Se registra contra el modelo Eloquent del tramo porque es lo que
         * Laravel usa para resolver una policy por clase, igual que hace
         * `Workforce` con `Employee`. Lo que se autoriza no es esa fila —los
         * tres endpoints comprueban rol, no propiedad— sino el tipo de recurso.
         */
        Gate::policy(ShiftEntry::class, ShiftEntryPolicy::class);

        /*
         * RN-06 y regla dura 7: `daily_totals` es una proyeccion reconstruible y
         * la mantiene un listener, no el repositorio.
         *
         * El agregado emite `DailyTotalsRecalculated` con el estado COMPLETO del
         * dia —nunca un delta— y el proyector escribe lo que recibe. Corre
         * **dentro** de la transaccion del fichaje porque el caso de uso publica
         * antes de confirmar: la proyeccion no puede quedar divergente del tramo
         * que la provoco.
         *
         * Sincrono y sin `ShouldQueue` a proposito. Encolarlo dejaria una
         * ventana —de milisegundos o de minutos, segun la cola— en la que el
         * panel de presencia mostraria el total de antes del fichaje que acaba
         * de ocurrir.
         */
        Event::listen(DailyTotalsRecalculated::class, [DailyTotalsProjector::class, 'handle']);

        if ($this->app->runningInConsole()) {
            /*
             * `attendance:detect-incidents` (RF-PR-01, doc 02 Anexo C). CUANDO se
             * ejecuta lo dice `routes/console.php`; que exista, esto.
             *
             * Vive en `Attendance` y no en `Compliance` porque quien sabe leer una
             * jornada es este modulo: las reglas que evalua —RN-07, RN-08, RN-10,
             * RN-11, RN-12 y RN-15— son suyas. Las incidencias que salen de ahi
             * las abre `Compliance` reaccionando a sus eventos.
             */
            /*
             * `attendance:reconcile` (RF-PR-02, doc 02 Anexo C, ADR-007). Igual
             * que el anterior: CUANDO se ejecuta lo dice `routes/console.php`.
             *
             * Vive en `Attendance` porque lo que contrasta es una jornada, y
             * quien sabe sumarla es este modulo (RN-06). La traza de la
             * correccion la escribe `Compliance` reaccionando a su evento.
             */
            $this->commands([DetectIncidentsCommand::class, ReconcileProjectionsCommand::class]);
        }
    }

    /**
     * Las tres zonas de fichaje de la capa de Aplicacion (§7.1, §7.5, RS-02,
     * RS-12).
     *
     * **Tres y no una**, aunque las tres rutas empiecen por `/api/v1/scan`:
     *
     * - `scan` cuenta escaneos sueltos, uno por peticion.
     * - `scan-batch` cuenta lotes de hasta cincuenta, asi que con el mismo
     *   contador habria que elegir entre asfixiar el drenaje de la cola o dejar
     *   el endpoint individual practicamente sin techo.
     * - `scan-pin` no frena un ritmo de fichaje, frena **fuerza bruta** sobre un
     *   espacio de 10^6 (RS-12, tarea 1.12). Sus numeros razonables son dos
     *   ordenes de magnitud mas bajos: una persona teclea un codigo y seis
     *   digitos en decenas de segundos.
     *
     * **El techo por IP de la zona del PIN tambien es propio y mas estrecho.** El
     * de las otras dos se fija al valor del borde para que mande Nginx; aqui la
     * pregunta es otra —«¿cuantos PIN se pueden probar por minuto desde un
     * sitio?»— y heredar los 600 generales habria dejado ese limite sin efecto
     * practico. El §7.5 lo pide por escrito como control **independiente** del
     * bloqueo por empleado: uno frena a quien prueba muchos PIN de una persona,
     * el otro a quien prueba un PIN de mucha gente, y ninguno ve lo que ve el
     * otro.
     *
     * Los numeros salen de `config/kiosk.php` y no son constantes (regla dura
     * 13): un hotel con veinte quioscos no tiene el mismo techo razonable que uno
     * con dos, y cambiarlo no puede exigir tocar el repositorio.
     *
     * La zona `kiosk` —padron y latido— la registra `KioskServiceProvider`, que es
     * el modulo de esos endpoints.
     */
    private function registerScanRateLimiters(): void
    {
        $perIp = Config::integer('kiosk.rate_limits.per_ip', 600);

        RateLimiter::for('scan', static fn (Request $request): array => KioskRateLimit::of(
            $request,
            'scan',
            Config::integer('kiosk.rate_limits.scan_per_device', 120),
            $perIp,
        ));

        RateLimiter::for('scan-batch', static fn (Request $request): array => KioskRateLimit::of(
            $request,
            'scan-batch',
            Config::integer('kiosk.rate_limits.batch_per_device', 60),
            $perIp,
        ));

        RateLimiter::for('scan-pin', static fn (Request $request): array => KioskRateLimit::of(
            $request,
            'scan-pin',
            Config::integer('kiosk.rate_limits.pin_scan_per_device', 10),
            Config::integer('kiosk.rate_limits.pin_scan_per_ip', 60),
        ));
    }
}
