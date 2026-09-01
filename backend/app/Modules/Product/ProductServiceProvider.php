<?php

declare(strict_types=1);

namespace App\Modules\Product;

use App\Modules\Identity\Domain\Event\DeviceTokenIssued;
use App\Modules\Product\Application\Port\ComplianceProfileMetrics;
use App\Modules\Product\Application\Port\ComplianceProfileRepository;
use App\Modules\Product\Application\Port\LicenseMetrics;
use App\Modules\Product\Application\Port\LicenseRepository;
use App\Modules\Product\Application\Port\LicenseStatePublisher;
use App\Modules\Product\Application\Port\LicenseVerifier;
use App\Modules\Product\Application\Port\PlanUsageCounter;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Application\Port\SettingsAnomalyReporter;
use App\Modules\Product\Application\Port\SettingsMetrics;
use App\Modules\Product\Application\Port\SettingsRepository;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Product\Application\UseCase\GetLicenseStatusHandler;
use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Application\UseCase\RecordPlanUsageHandler;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileSnapshot;
use App\Modules\Product\Domain\ValueObject\LicenseStatus;
use App\Modules\Product\Domain\ValueObject\ResolvedSettings;
use App\Modules\Product\Http\Policy\ComplianceProfilePolicy;
use App\Modules\Product\Http\Policy\LicensePolicy;
use App\Modules\Product\Http\Policy\SettingsPolicy;
use App\Modules\Product\Infrastructure\Adapter\CachedLicenseStatePublisher;
use App\Modules\Product\Infrastructure\Adapter\CachedSettingsRepository;
use App\Modules\Product\Infrastructure\Adapter\DbBrandingProvider;
use App\Modules\Product\Infrastructure\Adapter\DbCompliancePolicyProvider;
use App\Modules\Product\Infrastructure\Adapter\DbOperationalSettingsProvider;
use App\Modules\Product\Infrastructure\Adapter\Ed25519LicenseVerifier;
use App\Modules\Product\Infrastructure\Adapter\LaravelProductEventPublisher;
use App\Modules\Product\Infrastructure\Adapter\LicensedFeatureGate;
use App\Modules\Product\Infrastructure\Adapter\LoggingSettingsAnomalyReporter;
use App\Modules\Product\Infrastructure\Console\LicenseActivateCommand;
use App\Modules\Product\Infrastructure\Console\LicenseShowCommand;
use App\Modules\Product\Infrastructure\Listener\ObservePlanLimits;
use App\Modules\Product\Infrastructure\Metrics\RedisComplianceProfileMetrics;
use App\Modules\Product\Infrastructure\Metrics\RedisLicenseMetrics;
use App\Modules\Product\Infrastructure\Metrics\RedisSettingsMetrics;
use App\Modules\Product\Infrastructure\Persistence\DatabaseComplianceProfileRepository;
use App\Modules\Product\Infrastructure\Persistence\DatabaseLicenseRepository;
use App\Modules\Product\Infrastructure\Persistence\DatabasePlanUsageCounter;
use App\Modules\Product\Infrastructure\Persistence\EloquentSettingsRepository;
use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\CompliancePolicyProvider;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Application\Port\OperationalSettingsProvider;
use App\Modules\Workforce\Domain\Event\EmployeeHired;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Modulo Product — configuracion de instalacion, perfiles de cumplimiento,
 * marca, licencia, diagnostico y soporte (doc 02 §1.6). Existe para que la
 * diferencia entre clientes sea dato y no codigo (regla dura 13, ADR-017).
 *
 * Ningun modulo lee esta configuracion directamente: recibe el valor ya resuelto
 * o un puerto tipado (ADR-025). Los tres puertos transversales viven en
 * `Shared/Application/Port/` y sus adaptadores aqui, que es donde estan las
 * tablas.
 *
 * ## Los tres proveedores, y por que dos existian antes de la tarea 5.1
 *
 * - **`OperationalSettingsProvider`** se enlaza desde la tarea 1.3, porque sin el
 *   el fichaje no tendria de donde sacar la ventana anti-rebote de RF-AT-06 ni la
 *   duracion anomala de RN-08, y la unica alternativa era escribir 60 s y 12 h
 *   como constantes en PHP — lo que la regla dura 14 prohibe. La 5.1 le cambia
 *   por dentro de donde salen los valores (la cascada) y le añade la edicion
 *   desde el panel; la forma que ve el nucleo no cambia.
 * - **`CompliancePolicyProvider`** se enlaza desde la tarea 2.6 y por el mismo
 *   motivo. La 5.2 le añade la edicion y la auditoria del cambio.
 * - **`BrandingProvider`** lo enlaza esta tarea (RF-PD-08). La 5.8 migra a el los
 *   dos consumidores que hoy leen `config('branding.*')` —`BrowsershotCardRenderer`
 *   y `CsvLegalExportWriter`— y añade la pantalla del panel.
 *
 * ## `scoped()` y no `singleton()`
 *
 * Los tres proveedores y el repositorio memoizan **por peticion**: el fichaje
 * pide la configuracion en cada escaneo y no tiene sentido resolver la cascada
 * nueve veces. `singleton()` sobrevive a la peticion en un trabajador de cola o
 * en Octane, y ahi la memoria dejaria de ser «por peticion» para convertirse en
 * una cache sin invalidacion: un cambio guardado en el panel no se aplicaria
 * hasta reiniciar el proceso. `scoped()` es el mismo objeto durante la peticion
 * y uno nuevo en la siguiente, que es exactamente lo que estas clases documentan
 * — el mismo criterio que `RetentionServiceProvider`.
 *
 * ## Por que `SettingsRepository` se compone a mano y no por autowiring
 *
 * Porque lo que el resto del sistema recibe **no** es el repositorio, es el
 * repositorio con la cache delante: la configuracion se lee en cada escaneo y a
 * cincuenta por segundo (RNF-P-06) serian cincuenta consultas por segundo a una
 * tabla de nueve filas que cambia una vez al año. Con un enlace directo a la
 * implementacion de base de datos, el que se olvidara de pedir el decorador
 * tendria un producto que funciona igual y consulta mil veces mas.
 */
final class ProductServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * `installation_settings` con la cache delante.
         *
         * `scoped()`, y no por ahorro: `CachedSettingsRepository` registra un
         * `afterCommit` sobre la conexion para volver a invalidar cuando la
         * transaccion confirma, y dos instancias distintas dentro de la misma
         * peticion invalidarian la misma clave dos veces sin necesidad. La cache
         * de verdad es Redis y es compartida entre procesos; esto solo fija que
         * haya un unico objeto que la gobierne mientras dura la peticion.
         */
        $this->app->scoped(
            SettingsRepository::class,
            static fn (Application $app): CachedSettingsRepository => new CachedSettingsRepository(
                settings: new EloquentSettingsRepository($app->make(Clock::class)),
                cache: $app->make(CacheRepository::class),
                // La conexion concreta y no `ConnectionInterface`:
                // `afterCommit()` lo declara `Connection`, no la interfaz.
                connection: DB::connection(),
            ),
        );

        /*
         * El aviso de que hay configuracion guardada que no se puede aplicar.
         *
         * Se agrupa por ventana porque quien lee la configuracion es el camino de
         * fichaje: un `warning` por escaneo serian cincuenta por segundo. La
         * ventana se lee aqui y no dentro del adaptador porque el enlace se
         * resuelve por peticion, asi que `config:cache` y una prueba que cambie
         * el valor surten efecto igual.
         */
        $this->app->scoped(
            SettingsAnomalyReporter::class,
            static fn (Application $app): LoggingSettingsAnomalyReporter => new LoggingSettingsAnomalyReporter(
                logger: Log::channel(),
                cache: $app->make(CacheRepository::class),
                windowSeconds: Config::integer('product.settings_anomaly_window_seconds', 300),
            ),
        );

        // `installation_setting_changes_total{affects_worked_hours}` (doc 02 §8.2).
        $this->app->bind(
            SettingsMetrics::class,
            static fn (Application $app): RedisSettingsMetrics => new RedisSettingsMetrics($app->make(Redis::class)),
        );

        // Memoria por peticion: el fichaje pide la configuracion en cada escaneo
        // y resolver la cascada nueve veces por peticion no aporta nada.
        $this->app->scoped(
            OperationalSettingsProvider::class,
            static fn (Application $app): DbOperationalSettingsProvider => new DbOperationalSettingsProvider(
                $app->make(GetSettingsHandler::class),
            ),
        );

        // La marca (RF-PD-08). Memoria por peticion por lo mismo: se pide una vez
        // por documento y varias veces por pantalla.
        $this->app->scoped(
            BrandingProvider::class,
            static fn (Application $app): DbBrandingProvider => new DbBrandingProvider(
                $app->make(GetSettingsHandler::class),
            ),
        );

        // Los umbrales legales (RF-PD-07). Memoria por peticion por la misma
        // razon: la vista de cumplimiento los pedira una vez por jornada de un
        // informe, y son una fila que cambia cuando cambia el convenio.
        $this->app->scoped(
            CompliancePolicyProvider::class,
            static fn (): DbCompliancePolicyProvider => new DbCompliancePolicyProvider(DB::connection(), Log::channel()),
        );

        /*
         * El mismo perfil, pero para EDITARLO (tarea 5.2).
         *
         * Dos objetos y no uno: el de arriba esta en el camino de fichaje y
         * devuelve minutos sin nombre ni identificador; este devuelve lo que el
         * panel edita y lo que hace falta para escribir un asiento con el valor
         * anterior. `scoped()` por lo mismo que el resto —memoria por peticion,
         * nunca entre peticiones— y ademas porque el repositorio invalida su
         * propia memoria al guardar.
         */
        $this->app->scoped(
            ComplianceProfileRepository::class,
            static fn (): DatabaseComplianceProfileRepository => new DatabaseComplianceProfileRepository(DB::connection(), Log::channel()),
        );

        // `compliance_profile_changes_total{effect}` (doc 02 §8.2).
        $this->app->bind(
            ComplianceProfileMetrics::class,
            static fn (Application $app): RedisComplianceProfileMetrics => new RedisComplianceProfileMetrics($app->make(Redis::class)),
        );

        $this->app->bind(
            ProductEventPublisher::class,
            static fn (Application $app): LaravelProductEventPublisher => new LaravelProductEventPublisher(
                $app->make(Dispatcher::class),
            ),
        );

        $this->registerLicense();
    }

    /**
     * La licencia (tarea 5.3, RF-PD-04, ADR-018, ADR-023, ADR-028).
     *
     * ## El verificador recibe la clave publica ya resuelta
     *
     * `config/license.php` se lee **aqui**, en el borde, y no dentro del
     * adaptador: es la misma regla que con los umbrales legales (regla dura 14)
     * y con los topes de los informes. Una clase que consulta la configuracion
     * global no se puede probar con dos claves publicas distintas sin tocar el
     * estado de todo el proceso, y esta suite necesita justamente eso — genera
     * un par ed25519 nuevo en cada ejecucion y jamas usa uno fijo del
     * repositorio.
     *
     * ## `scoped()` para el `FeatureGate`
     *
     * Memoria **por peticion**: el estado se resuelve una vez y lo comparten el
     * informe, la presencia y lo que venga. `singleton()` sobrevive a la
     * peticion en un trabajador de cola o en Octane, y ahi dejaria de ser
     * memoria para ser una cache sin invalidacion: una clave recien activada no
     * surtiria efecto hasta reiniciar el proceso. Es el mismo criterio de los
     * cuatro proveedores de la tarea 5.1.
     *
     * ## `bind()` para todo lo demas
     *
     * Los dos casos de uso y los adaptadores de persistencia no memorizan nada:
     * el repositorio consulta una fila por un indice unico y el contador dos
     * agregados que solo se piden en la pantalla de licencia y en la consola.
     */
    private function registerLicense(): void
    {
        $this->app->bind(
            LicenseVerifier::class,
            static fn (): Ed25519LicenseVerifier => new Ed25519LicenseVerifier(
                Config::string('license.public_key'),
            ),
        );

        $this->app->bind(
            LicenseRepository::class,
            static fn (): DatabaseLicenseRepository => new DatabaseLicenseRepository(DB::connection(), Log::channel()),
        );

        $this->app->bind(
            PlanUsageCounter::class,
            static fn (): DatabasePlanUsageCounter => new DatabasePlanUsageCounter(DB::connection(), Log::channel()),
        );

        // `license_limit_exceeded_total{limit}` (doc 02 §8.2).
        $this->app->bind(
            LicenseMetrics::class,
            static fn (Application $app): RedisLicenseMetrics => new RedisLicenseMetrics($app->make(Redis::class)),
        );

        /*
         * La copia del estado que lee `GET /api/v1/health` SIN TOCAR NADA.
         *
         * La escribe el punto unico de resolucion y no el `FeatureGate`: si solo
         * publicara el gate, la sonda seguiria diciendo `unknown` justo despues
         * de activar una clave —que es cuando alguien la mira— hasta que una
         * pantalla pidiera una funcionalidad accesoria.
         */
        $this->app->bind(
            LicenseStatePublisher::class,
            static fn (Application $app): CachedLicenseStatePublisher => new CachedLicenseStatePublisher(
                cache: $app->make(CacheRepository::class),
                ttlSeconds: Config::integer('license.health_probe_ttl_seconds', 600),
            ),
        );

        $this->app->bind(
            GetLicenseStatusHandler::class,
            static fn (Application $app): GetLicenseStatusHandler => new GetLicenseStatusHandler(
                licenses: $app->make(LicenseRepository::class),
                verifier: $app->make(LicenseVerifier::class),
                probe: $app->make(LicenseStatePublisher::class),
                clock: $app->make(Clock::class),
                expiryWarningDays: Config::integer('license.expiry_warning_days', 30),
            ),
        );

        $this->app->bind(
            ActivateLicenseHandler::class,
            static fn (Application $app): ActivateLicenseHandler => new ActivateLicenseHandler(
                licenses: $app->make(LicenseRepository::class),
                verifier: $app->make(LicenseVerifier::class),
                events: $app->make(ProductEventPublisher::class),
                clock: $app->make(Clock::class),
                connection: DB::connection(),
                expiryWarningDays: Config::integer('license.expiry_warning_days', 30),
            ),
        );

        /*
         * EL PUNTO UNICO DE DECISION de ADR-023.
         *
         * Todo el que quiera saber si una funcionalidad accesoria esta
         * disponible pide este puerto y nada mas.
         * `tests/Architecture/LicenseBoundaryTest.php` comprueba que no hay otra
         * via: ningun fichero fuera de `Product` nombra la tabla `license` ni el
         * estado de la licencia.
         */
        $this->app->scoped(
            FeatureGate::class,
            static fn (Application $app): LicensedFeatureGate => new LicensedFeatureGate(
                licenses: $app->make(GetLicenseStatusHandler::class),
                logger: Log::channel(),
            ),
        );

        /*
         * El observador de los limites (ADR-028).
         *
         * Se compone a mano por `DB::connection()`: el contenedor no resuelve
         * `Illuminate\Database\Connection` por autowiring, y hace falta la clase
         * concreta porque `afterCommit()` no esta en la interfaz. Es lo que
         * mantiene el conteo fuera de la transaccion del alta.
         */
        $this->app->bind(
            ObservePlanLimits::class,
            static fn (Application $app): ObservePlanLimits => new ObservePlanLimits(
                usage: $app->make(RecordPlanUsageHandler::class),
                logger: Log::channel(),
                connection: DB::connection(),
            ),
        );
    }

    public function boot(): void
    {
        /*
         * `GET` y `PATCH /api/v1/settings` son de `admin` y de nadie mas (Anexo B
         * del doc 01, §7.3 del doc 02). El ambito `settings:*` lo comprueba el
         * middleware; esta policy comprueba el rol, que es la mitad que impide
         * que un token emitido a mano con el ambito correcto cambie el umbral con
         * el que se calculan las horas del centro (regla dura 18).
         *
         * El sujeto de la autorizacion es {@see ResolvedSettings} —«la
         * configuracion de la instalacion»— y no el modelo Eloquent: la policy no
         * autoriza sobre una fila, autoriza sobre el conjunto, y no hay ninguna
         * operacion por fila que pudiera necesitar la otra.
         */
        Gate::policy(ResolvedSettings::class, SettingsPolicy::class);

        /*
         * `GET` y `PATCH /api/v1/compliance-profile` son de `admin` y de nadie
         * mas, con el mismo reparto que la configuracion (§7.3 del doc 02: el
         * ambito `settings:*` es del administrador de instalacion).
         *
         * El sujeto es {@see ComplianceProfileSnapshot} —«el perfil vigente del
         * centro»— y no una fila: la policy no autoriza sobre un perfil concreto,
         * porque solo hay uno vigente y no hay ninguna operacion por fila que
         * pudiera necesitar la otra.
         */
        Gate::policy(ComplianceProfileSnapshot::class, ComplianceProfilePolicy::class);

        /*
         * `GET /api/v1/license` y `POST /api/v1/license/activate` son de `admin`
         * y de nadie mas (Anexo B del doc 01, §7.3: `license:*` es del
         * administrador de instalacion). El middleware comprueba el ambito y
         * esta policy el rol (regla dura 18).
         *
         * El sujeto es {@see LicenseStatus} —«la licencia de esta
         * instalacion»— y no una fila: **la fila puede no existir**, y una
         * policy que autorizara sobre un modelo dejaria sin respuesta el caso
         * mas comun de una puesta en marcha.
         */
        Gate::policy(LicenseStatus::class, LicensePolicy::class);

        $this->observePlanLimits();

        if ($this->app->runningInConsole()) {
            /*
             * Los dos comandos del Anexo C (RF-PD-04).
             *
             * **NO van en `routes/console.php`**: ahi vive cuando se ejecuta
             * cada comando programado, y ninguno de estos dos se programa.
             * `license:show` lo ejecuta una persona que quiere saber como esta
             * su licencia —o `doctor` en la 5.9—, y `license:activate` lo
             * ejecuta quien acaba de recibir una clave, o el instalador de la
             * 5.4 la primera vez.
             *
             * Y sobre todo: **una comprobacion de licencia programada no existe
             * en este producto**. No hay tarea nocturna que revise la licencia
             * y cambie nada, porque el estado se calcula al preguntarlo y
             * porque una tarea asi es el primer paso hacia un producto que se
             * apaga solo una madrugada (ADR-019).
             */
            $this->commands([
                LicenseShowCommand::class,
                LicenseActivateCommand::class,
            ]);
        }
    }

    /**
     * El observador de los limites del plan (**ADR-028**, tarea 5.3).
     *
     * ## Escucha altas, no las intercepta
     *
     * `EmployeeHired` y `DeviceTokenIssued` se publican cuando el alta **ya
     * ocurrio**: la persona esta en plantilla y el quiosco tiene su token. Este
     * listener no participa en esas decisiones y no puede impedirlas, que es
     * exactamente lo que ADR-028 exige. Bloquear el alta dejaria a alguien
     * trabajando sin registro horario y bloquear el emparejamiento dejaria un
     * centro sin punto de fichaje.
     *
     * ## `afterCommit` y sin `ShouldQueue`
     *
     * `afterCommit` porque contar antes de que el alta confirme daria una cifra
     * que todavia no es cierta —y, si la transaccion se revirtiera, un asiento
     * de exceso por un alta que nunca existio—. **Sin `ShouldQueue`** porque el
     * asiento que produce es la evidencia comercial de ADR-028 y no puede
     * depender de que la cola este viva.
     *
     * La contrapartida —que el trabajo ocurra en la peticion del alta— esta
     * acotada: son dos `count(*)` y, si algo falla, `ObservePlanLimits` lo
     * atrapa y el alta sigue su camino.
     *
     * ## Ni `Workforce` ni `Identity` conocen la licencia
     *
     * Y es la otra mitad de la promesa. Ninguno de los dos modulos importa nada
     * de `Product`: publican su evento como ya hacian para la auditoria, y quien
     * cuenta es este modulo. Sin aristas nuevas en el §1.6 mas alla de los dos
     * eventos, que es la misma concesion por la que `Compliance` sella el alta.
     */
    private function observePlanLimits(): void
    {
        Event::listen(EmployeeHired::class, [ObservePlanLimits::class, 'onEmployeeHired']);
        Event::listen(DeviceTokenIssued::class, [ObservePlanLimits::class, 'onDeviceTokenIssued']);
    }
}
