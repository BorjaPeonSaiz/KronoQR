<?php

declare(strict_types=1);

namespace App\Modules\Product;

use App\Modules\Identity\Domain\Event\DeviceTokenIssued;
use App\Modules\Product\Application\Port\ComplianceProfileMetrics;
use App\Modules\Product\Application\Port\ComplianceProfileRepository;
use App\Modules\Product\Application\Port\DataExportArchiveWriter;
use App\Modules\Product\Application\Port\DataExportGuide;
use App\Modules\Product\Application\Port\DataExportQueue;
use App\Modules\Product\Application\Port\DataExportRepository;
use App\Modules\Product\Application\Port\DataExportSource;
use App\Modules\Product\Application\Port\DiagnosticsBundleWriter;
use App\Modules\Product\Application\Port\DoctorTranslator;
use App\Modules\Product\Application\Port\LicenseMetrics;
use App\Modules\Product\Application\Port\LicenseRepository;
use App\Modules\Product\Application\Port\LicenseStatePublisher;
use App\Modules\Product\Application\Port\LicenseVerifier;
use App\Modules\Product\Application\Port\LogoInspector;
use App\Modules\Product\Application\Port\PlanUsageCounter;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Application\Port\SettingsAnomalyReporter;
use App\Modules\Product\Application\Port\SettingsMetrics;
use App\Modules\Product\Application\Port\SettingsRepository;
use App\Modules\Product\Application\Port\SetupFacts;
use App\Modules\Product\Application\Port\SetupProgressRepository;
use App\Modules\Product\Application\Port\SupportAccessRecorder;
use App\Modules\Product\Application\Port\SupportGrantRepository;
use App\Modules\Product\Application\Port\SupportTokenIssuer;
use App\Modules\Product\Application\Port\TelemetryCounters;
use App\Modules\Product\Application\Port\TelemetryFacts;
use App\Modules\Product\Application\Port\TelemetrySender;
use App\Modules\Product\Application\Port\TelemetryStateStore;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Product\Application\UseCase\BuildTelemetryReportHandler;
use App\Modules\Product\Application\UseCase\DownloadDataExportHandler;
use App\Modules\Product\Application\UseCase\GenerateDataExportHandler;
use App\Modules\Product\Application\UseCase\GenerateDiagnosticsBundleHandler;
use App\Modules\Product\Application\UseCase\GetLicenseStatusHandler;
use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Application\UseCase\GrantSupportAccessHandler;
use App\Modules\Product\Application\UseCase\ListDataExportsHandler;
use App\Modules\Product\Application\UseCase\PurgeExpiredDataExportsHandler;
use App\Modules\Product\Application\UseCase\RecordPlanUsageHandler;
use App\Modules\Product\Application\UseCase\RecordSupportAccessUseHandler;
use App\Modules\Product\Application\UseCase\RequestDataExportHandler;
use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Application\UseCase\SendTelemetryHandler;
use App\Modules\Product\Domain\Model\DataExport as DataExportModel;
use App\Modules\Product\Domain\Model\SupportGrant as SupportGrantModel;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileSnapshot;
use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use App\Modules\Product\Domain\ValueObject\LicenseStatus;
use App\Modules\Product\Domain\ValueObject\ResolvedSettings;
use App\Modules\Product\Domain\ValueObject\SetupState;
use App\Modules\Product\Http\Policy\ComplianceProfilePolicy;
use App\Modules\Product\Http\Policy\DataExportPolicy;
use App\Modules\Product\Http\Policy\DiagnosticsPolicy;
use App\Modules\Product\Http\Policy\LicensePolicy;
use App\Modules\Product\Http\Policy\SettingsPolicy;
use App\Modules\Product\Http\Policy\SetupPolicy;
use App\Modules\Product\Http\Policy\SupportGrantPolicy;
use App\Modules\Product\Infrastructure\Adapter\CachedLicenseStatePublisher;
use App\Modules\Product\Infrastructure\Adapter\CachedSettingsRepository;
use App\Modules\Product\Infrastructure\Adapter\CacheSupportAccessRecorder;
use App\Modules\Product\Infrastructure\Adapter\DbBrandingProvider;
use App\Modules\Product\Infrastructure\Adapter\DbCompliancePolicyProvider;
use App\Modules\Product\Infrastructure\Adapter\DbLocalePolicyProvider;
use App\Modules\Product\Infrastructure\Adapter\DbOperationalSettingsProvider;
use App\Modules\Product\Infrastructure\Adapter\Ed25519LicenseVerifier;
use App\Modules\Product\Infrastructure\Adapter\LaravelProductEventPublisher;
use App\Modules\Product\Infrastructure\Adapter\LicensedBrandingProvider;
use App\Modules\Product\Infrastructure\Adapter\LicensedFeatureGate;
use App\Modules\Product\Infrastructure\Adapter\LocalBrandingLogoReader;
use App\Modules\Product\Infrastructure\Adapter\LoggingSettingsAnomalyReporter;
use App\Modules\Product\Infrastructure\Adapter\QueuedDataExportDispatcher;
use App\Modules\Product\Infrastructure\Adapter\SanctumSupportTokenIssuer;
use App\Modules\Product\Infrastructure\Branding\LogoFileInspector;
use App\Modules\Product\Infrastructure\Console\LicenseActivateCommand;
use App\Modules\Product\Infrastructure\Console\LicenseShowCommand;
use App\Modules\Product\Infrastructure\Console\ProductDiagnosticsCommand;
use App\Modules\Product\Infrastructure\Console\ProductDoctorCommand;
use App\Modules\Product\Infrastructure\Console\ProductExportAllCommand;
use App\Modules\Product\Infrastructure\Console\ProductTelemetryCommand;
use App\Modules\Product\Infrastructure\Console\SupportGrantCommand;
use App\Modules\Product\Infrastructure\Console\SupportRevokeCommand;
use App\Modules\Product\Infrastructure\Diagnostics\Collector\AuditCollector;
use App\Modules\Product\Infrastructure\Diagnostics\Collector\ConfigurationCollector;
use App\Modules\Product\Infrastructure\Diagnostics\Collector\DoctorCollector;
use App\Modules\Product\Infrastructure\Diagnostics\Collector\ErrorEventsCollector;
use App\Modules\Product\Infrastructure\Diagnostics\Collector\InstallationCollector;
use App\Modules\Product\Infrastructure\Diagnostics\Collector\KioskCollector;
use App\Modules\Product\Infrastructure\Diagnostics\Collector\LicenseCollector;
use App\Modules\Product\Infrastructure\Diagnostics\Collector\MetricsCollector;
use App\Modules\Product\Infrastructure\Diagnostics\Collector\PersonalDataCollector;
use App\Modules\Product\Infrastructure\Diagnostics\Collector\ServicesCollector;
use App\Modules\Product\Infrastructure\Diagnostics\Collector\UpdatesCollector;
use App\Modules\Product\Infrastructure\Diagnostics\JsonDiagnosticsBundleWriter;
use App\Modules\Product\Infrastructure\Diagnostics\LaravelDoctorTranslator;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\ApplicationProbe;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\DatabaseProbe;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\DiskProbe;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\LicenseProbe;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\MailProbe;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\PermissionsProbe;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\QueueProbe;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\SettingsProbe;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\TlsProbe;
use App\Modules\Product\Infrastructure\Diagnostics\ServiceInspector;
use App\Modules\Product\Infrastructure\Export\TranslatedDataExportGuide;
use App\Modules\Product\Infrastructure\Export\ZipDataExportArchiveWriter;
use App\Modules\Product\Infrastructure\Listener\ObservePlanLimits;
use App\Modules\Product\Infrastructure\Metrics\RedisComplianceProfileMetrics;
use App\Modules\Product\Infrastructure\Metrics\RedisLicenseMetrics;
use App\Modules\Product\Infrastructure\Metrics\RedisSettingsMetrics;
use App\Modules\Product\Infrastructure\Persistence\DatabaseComplianceProfileRepository;
use App\Modules\Product\Infrastructure\Persistence\DatabaseDataExportRepository;
use App\Modules\Product\Infrastructure\Persistence\DatabaseDataExportSource;
use App\Modules\Product\Infrastructure\Persistence\DatabaseLicenseRepository;
use App\Modules\Product\Infrastructure\Persistence\DatabasePlanUsageCounter;
use App\Modules\Product\Infrastructure\Persistence\DatabaseSetupFacts;
use App\Modules\Product\Infrastructure\Persistence\DatabaseSetupProgressRepository;
use App\Modules\Product\Infrastructure\Persistence\DatabaseSupportGrantRepository;
use App\Modules\Product\Infrastructure\Persistence\EloquentSettingsRepository;
use App\Modules\Product\Infrastructure\Telemetry\DatabaseTelemetryFacts;
use App\Modules\Product\Infrastructure\Telemetry\FileTelemetryStateStore;
use App\Modules\Product\Infrastructure\Telemetry\HttpTelemetrySender;
use App\Modules\Product\Infrastructure\Telemetry\RedisTelemetryCounters;
use App\Modules\Shared\Application\Port\BrandingLogoReader;
use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\CompliancePolicyProvider;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Application\Port\LocalePolicyProvider;
use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Application\Port\OperationalSettingsProvider;
use App\Modules\Workforce\Domain\Event\EmployeeHired;
use App\Support\Locale\NegotiableLocales;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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
 * - **`BrandingProvider`** lo enlaza la tarea 5.1 (RF-PD-08). La 5.8 le suma
 *   `BrandingLogoReader` —el logotipo, ya leido y ya comprobado— y
 *   `LocalePolicyProvider`, y pasa a los tres a sus consumidores:
 *   `BrowsershotCardRenderer`, `CsvLegalExportWriter`, `PeriodReportPdf` y las
 *   tres SPA a traves de `GET /api/v1/branding`. Ninguno lee ya
 *   `config('branding.*')`.
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
    /**
     * Cuantas veces el cubo por IP del limitador de la exportacion integra es
     * mayor que el cubo por cuenta.
     *
     * En un hotel varios administradores comparten IP publica y el panel sondea
     * mientras se genera: con el mismo techo en los dos ejes se cortarian entre
     * si. Ver el comentario de `RateLimiter::for('data-export', ...)`.
     */
    private const int DATA_EXPORT_IP_BUDGET_FACTOR = 4;

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

        /*
         * La marca (RF-PD-08), CON EL PLAN DELANTE (ADR-023).
         *
         * Dos objetos y no uno: `DbBrandingProvider` resuelve la cascada de
         * configuracion y `LicensedBrandingProvider` decide si esa marca se
         * aplica o se vuelve a la del fabricante. Separarlos es lo que permite
         * que el gating se retire quitando un enlace, y no editando una consulta.
         *
         * **El decorador va fuera**, asi que todo el que pida el puerto —los
         * cuatro consumidores de hoy y los que vengan— recibe ya la marca que
         * corresponde y no se entera de que existe una licencia. Es la defensa
         * literal que pide ADR-023 contra el `if (license.expired)` repartido.
         *
         * Memoria por peticion en los dos: la marca se pide una vez por documento
         * y varias veces por pantalla.
         */
        $this->app->scoped(
            DbBrandingProvider::class,
            static fn (Application $app): DbBrandingProvider => new DbBrandingProvider(
                $app->make(GetSettingsHandler::class),
            ),
        );

        $this->app->scoped(
            BrandingProvider::class,
            static fn (Application $app): LicensedBrandingProvider => new LicensedBrandingProvider(
                configured: $app->make(DbBrandingProvider::class),
                features: $app->make(FeatureGate::class),
            ),
        );

        $this->registerBranding();
        $this->registerLocales();

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

        $this->registerDiagnostics();

        $this->registerSupportGrants();

        $this->registerDataExport();

        $this->registerTelemetry();
    }

    /**
     * La telemetria opcional (tarea 5.10, **RF-PD-12**, ADR-020, ADR-023).
     *
     * ## Todo lo del entorno se resuelve AQUI
     *
     * Las dos condiciones de configuracion -`TELEMETRY_ENABLED` y
     * `TELEMETRY_ENDPOINT`-, la ruta del estado, la espera del reintento, la
     * version del producto y la de PHP entran ya resueltas en el caso de uso y
     * en los adaptadores. Es el mismo criterio que los umbrales legales (regla
     * dura 14), la clave publica de la licencia y los limites del logotipo: una
     * clase que consulta la configuracion global no se puede probar con la
     * telemetria encendida y apagada sin tocar el estado de todo el proceso, y
     * esta suite necesita justamente eso.
     *
     * ## `bind()` y no `scoped()`
     *
     * Nada de esto vive dentro de una peticion: el unico consumidor es un
     * comando de consola que se ejecuta una vez por semana. Memorizar una
     * instancia no ahorraria nada y solo abriria la puerta a que un estado
     * leido del disco sobreviviera a un `--send` posterior.
     *
     * ## El idioma del veredicto de `doctor` es el de soporte
     *
     * `DoctorCollector::SUPPORT_LOCALE`, la misma constante que usa el paquete
     * de diagnostico. Da igual para lo que viaja -solo salen `ok`, `warning` y
     * `failure`, que no se traducen-, pero fijarlo evita que el informe cambie
     * de forma segun el idioma que tenga puesta la instalacion.
     */
    private function registerTelemetry(): void
    {
        $this->app->bind(
            TelemetryStateStore::class,
            static fn (): FileTelemetryStateStore => new FileTelemetryStateStore(
                path: Config::string('product.telemetry_state_path'),
                // Para dejar constancia si el directorio no es escribible: sin
                // ella, la instalacion estrenaria identidad cada semana y nadie
                // sabria por que (ver el docblock de `save()`).
                logger: Log::channel(),
            ),
        );

        $this->app->bind(
            TelemetryCounters::class,
            static fn (Application $app): RedisTelemetryCounters => new RedisTelemetryCounters($app->make(Redis::class)),
        );

        $this->app->bind(
            TelemetryFacts::class,
            static fn (): DatabaseTelemetryFacts => new DatabaseTelemetryFacts(DB::connection()),
        );

        $this->app->bind(
            TelemetrySender::class,
            static fn (Application $app): HttpTelemetrySender => new HttpTelemetrySender(
                http: $app->make(HttpClient::class),
                productVersion: Config::string('app.version'),
                retryDelaySeconds: max(0, Config::integer('product.telemetry_retry_delay_seconds', 5)),
            ),
        );

        $this->app->bind(
            BuildTelemetryReportHandler::class,
            static fn (Application $app): BuildTelemetryReportHandler => new BuildTelemetryReportHandler(
                licenses: $app->make(GetLicenseStatusHandler::class),
                doctor: $app->make(RunDoctorHandler::class),
                usage: $app->make(PlanUsageCounter::class),
                counters: $app->make(TelemetryCounters::class),
                facts: $app->make(TelemetryFacts::class),
                clock: $app->make(Clock::class),
                productVersion: Config::string('app.version'),
                phpVersion: PHP_VERSION,
                doctorLocale: DoctorCollector::SUPPORT_LOCALE,
            ),
        );

        $this->app->bind(
            SendTelemetryHandler::class,
            static fn (Application $app): SendTelemetryHandler => new SendTelemetryHandler(
                reports: $app->make(BuildTelemetryReportHandler::class),
                sender: $app->make(TelemetrySender::class),
                state: $app->make(TelemetryStateStore::class),
                features: $app->make(FeatureGate::class),
                clock: $app->make(Clock::class),
                logger: Log::channel(),
                enabled: Config::boolean('product.telemetry_enabled'),
                endpoint: Config::string('product.telemetry_endpoint'),
            ),
        );
    }

    /**
     * Los accesos temporales de soporte (tarea 5.9, **RF-PD-11**, RL-18,
     * ADR-020).
     *
     * ## Los tres umbrales se leen AQUI, en el borde
     *
     * El tope de horas, la duracion de serie y la ventana de auditoria salen de
     * la configuracion y entran ya resueltos en el caso de uso (regla dura 14,
     * mismo criterio que el aviso de caducidad de la licencia y que los limites
     * del logotipo). Es lo que permite que una prueba fije un tope de una hora o
     * una ventana de cero sin tocar el estado global del proceso, y que un
     * cliente con una politica mas dura los baje sin tocar el repositorio (regla
     * dura 13).
     *
     * ## `scoped()` para el repositorio, `bind()` para lo demas
     *
     * El repositorio no memoriza nada, pero `scoped()` mantiene la coherencia
     * con el resto del modulo y evita que en un trabajador de cola una instancia
     * viva entre peticiones. Los casos de uso se declaran explicitamente porque
     * los tres reciben algo que el autowiring no puede resolver: un entero.
     *
     * ## El emisor del token vive en ESTE modulo, y no en `Identity`
     *
     * Aunque toda la maquinaria de sesion sea de aquel. El motivo es la frontera
     * del §1.6: el `tokenable` de una concesion es una fila de `support_grants`,
     * que es una tabla de `Product`, y un adaptador en `Identity` tendria que
     * tocar el modelo Eloquent de otro modulo —lo unico que ese apartado prohibe
     * sin matices—. Asi que aqui no hay inversion de ADR-025 que hacer: no son
     * dos modulos, es uno que necesita el token y que ademas tiene la tabla de la
     * que cuelga.
     *
     * Lo que si cruza la frontera es el **catalogo de ambitos**, y lo hace sin
     * dependencia: `SupportScope::abilities()` escribe las cadenas y una prueba
     * unitaria comprueba que todas existen en `Identity\Domain\ValueObject\TokenAbility`,
     * igual que las otras dos copias del catalogo —el contrato OpenAPI y la
     * migracion del catalogo de roles— estan atadas por pruebas y no por la buena
     * fe.
     */
    private function registerSupportGrants(): void
    {
        $this->app->scoped(
            SupportGrantRepository::class,
            static fn (): DatabaseSupportGrantRepository => new DatabaseSupportGrantRepository(DB::connection()),
        );

        $this->app->bind(
            SupportTokenIssuer::class,
            static fn (Application $app): SanctumSupportTokenIssuer => new SanctumSupportTokenIssuer(
                $app->make(SupportGrantRepository::class),
            ),
        );

        $this->app->bind(
            SupportAccessRecorder::class,
            static fn (Application $app): CacheSupportAccessRecorder => new CacheSupportAccessRecorder(
                $app->make(CacheRepository::class),
            ),
        );

        $this->app->bind(
            GrantSupportAccessHandler::class,
            static fn (Application $app): GrantSupportAccessHandler => new GrantSupportAccessHandler(
                grants: $app->make(SupportGrantRepository::class),
                tokens: $app->make(SupportTokenIssuer::class),
                events: $app->make(ProductEventPublisher::class),
                clock: $app->make(Clock::class),
                connection: DB::connection(),
                maximumHours: max(1, Config::integer('product.support_grant_max_hours', 72)),
            ),
        );

        $this->app->bind(
            RecordSupportAccessUseHandler::class,
            static fn (Application $app): RecordSupportAccessUseHandler => new RecordSupportAccessUseHandler(
                grants: $app->make(SupportGrantRepository::class),
                recorder: $app->make(SupportAccessRecorder::class),
                events: $app->make(ProductEventPublisher::class),
                clock: $app->make(Clock::class),
                connection: DB::connection(),
                windowSeconds: max(0, Config::integer('product.support_use_audit_window_seconds', 900)),
            ),
        );
    }

    /**
     * La exportacion integra de los datos del cliente (tarea 5.10, **RF-PD-14**,
     * RL-20).
     *
     * ## Los dos umbrales se leen AQUI, en el borde
     *
     * La retencion del fichero entra ya resuelta en el caso de uso (regla dura
     * 14, mismo criterio que los limites de las concesiones de soporte y que el
     * aviso de caducidad de la licencia), y la ruta del directorio entra ya
     * resuelta en el escritor. Es lo que permite que una prueba fije una
     * retencion de un dia o un directorio temporal sin tocar el estado global del
     * proceso, y que un cliente con una politica mas dura los cambie sin tocar el
     * repositorio (regla dura 13).
     *
     * ## `scoped()` para el repositorio, `bind()` para lo demas
     *
     * Igual que el resto del modulo: el repositorio memoriza por peticion —el
     * panel sondea la lista y la respuesta tiene que ser la de esta peticion, no
     * la de la anterior— y los casos de uso son objetos sin estado.
     *
     * ## Ninguno de los cinco recibe el `FeatureGate`
     *
     * Y no es un olvido: es ADR-019 y la regla dura 15 en el contenedor. La
     * exportacion integra **no se degrada nunca** con la licencia, porque RL-20
     * es la garantia de continuidad del cliente *«aunque la relacion comercial
     * termine»*. Que la puerta de la licencia no llegue siquiera a estas clases
     * es lo que impide que alguien la ate ahi mas adelante «por coherencia con el
     * resto».
     */
    private function registerDataExport(): void
    {
        $this->app->scoped(
            DataExportRepository::class,
            static fn (): DatabaseDataExportRepository => new DatabaseDataExportRepository(DB::connection()),
        );

        $this->app->bind(
            DataExportSource::class,
            static fn (): DatabaseDataExportSource => new DatabaseDataExportSource(DB::connection()),
        );

        $this->app->bind(
            DataExportArchiveWriter::class,
            static fn (): ZipDataExportArchiveWriter => new ZipDataExportArchiveWriter(
                Config::string('product.data_export_path'),
            ),
        );

        $this->app->bind(
            DataExportGuide::class,
            static fn (Application $app): TranslatedDataExportGuide => new TranslatedDataExportGuide(
                $app->make(Translator::class),
            ),
        );

        $this->app->bind(
            DataExportQueue::class,
            static fn (Application $app): QueuedDataExportDispatcher => new QueuedDataExportDispatcher(
                $app->make(BusDispatcher::class),
            ),
        );

        $this->app->bind(
            RequestDataExportHandler::class,
            static fn (Application $app): RequestDataExportHandler => new RequestDataExportHandler(
                exports: $app->make(DataExportRepository::class),
                queue: $app->make(DataExportQueue::class),
                events: $app->make(ProductEventPublisher::class),
                clock: $app->make(Clock::class),
                connection: DB::connection(),
                staleAfterSeconds: max(1, Config::integer('product.data_export_stale_after_seconds', 3600)),
            ),
        );

        $this->app->bind(
            GenerateDataExportHandler::class,
            static fn (Application $app): GenerateDataExportHandler => new GenerateDataExportHandler(
                exports: $app->make(DataExportRepository::class),
                source: $app->make(DataExportSource::class),
                writer: $app->make(DataExportArchiveWriter::class),
                guide: $app->make(DataExportGuide::class),
                events: $app->make(ProductEventPublisher::class),
                clock: $app->make(Clock::class),
                locales: $app->make(LocalePolicyProvider::class),
                connection: DB::connection(),
                productVersion: Config::string('app.version'),
                retentionDays: max(1, Config::integer('product.data_export_retention_days', 7)),
            ),
        );

        $this->app->bind(
            ListDataExportsHandler::class,
            static fn (Application $app): ListDataExportsHandler => new ListDataExportsHandler(
                $app->make(DataExportRepository::class),
            ),
        );

        $this->app->bind(
            DownloadDataExportHandler::class,
            static fn (Application $app): DownloadDataExportHandler => new DownloadDataExportHandler(
                exports: $app->make(DataExportRepository::class),
                events: $app->make(ProductEventPublisher::class),
                clock: $app->make(Clock::class),
                connection: DB::connection(),
            ),
        );

        $this->app->bind(
            PurgeExpiredDataExportsHandler::class,
            static fn (Application $app): PurgeExpiredDataExportsHandler => new PurgeExpiredDataExportsHandler(
                exports: $app->make(DataExportRepository::class),
                writer: $app->make(DataExportArchiveWriter::class),
                clock: $app->make(Clock::class),
                staleAfterSeconds: max(1, Config::integer('product.data_export_stale_after_seconds', 3600)),
            ),
        );
    }

    /**
     * El logotipo del cliente (tarea 5.8, RF-PD-08).
     *
     * ## Los tres limites se leen AQUI, en el borde
     *
     * Y no dentro del inspector. Es el mismo criterio con el que el verificador
     * de licencia recibe la clave publica ya resuelta y con el que el dominio
     * recibe los umbrales legales ya resueltos (regla dura 14): una clase que
     * consulta la configuracion global no se puede probar con dos directorios de
     * marca distintos sin tocar el estado de todo el proceso, y la suite de esta
     * tarea necesita justamente eso — cada prueba deja su fichero en un
     * directorio temporal propio.
     *
     * ## `bind()` para el inspector y `scoped()` para el lector
     *
     * El inspector no guarda nada: mira el disco cada vez que se le pregunta, y
     * debe hacerlo, porque uno de sus dos consumidores es la validacion del
     * `PATCH` y ahi la respuesta tiene que ser la del disco en ese instante.
     *
     * El lector si memoriza, y memoriza tambien el `null`: es lo que evita
     * volver a golpear el disco en cada intento cuando el fichero **no** esta.
     * `scoped()` y no `singleton()` por lo mismo que el resto del modulo — en un
     * trabajador de cola o en Octane, `singleton()` dejaria de ser memoria por
     * peticion para convertirse en una cache sin invalidacion, y un logotipo
     * recien cambiado no se veria hasta reiniciar el proceso.
     */
    private function registerBranding(): void
    {
        $this->app->bind(
            LogoInspector::class,
            static fn (): LogoFileInspector => new LogoFileInspector(
                logoRoot: Config::string('branding.logo_root'),
                maximumBytes: Config::integer('branding.logo_max_bytes'),
                maximumDimension: Config::integer('branding.logo_max_dimension'),
            ),
        );

        $this->app->scoped(
            BrandingLogoReader::class,
            static fn (Application $app): LocalBrandingLogoReader => new LocalBrandingLogoReader(
                branding: $app->make(BrandingProvider::class),
                inspector: $app->make(LogoInspector::class),
            ),
        );
    }

    /**
     * Los idiomas de la instalacion (tarea 5.8, RF-PD-01).
     *
     * ## Un objeto y DOS enlaces, a proposito
     *
     * `LocalePolicyProvider` es el puerto de `Shared` que usan los modulos —hoy,
     * el endpoint publico de la marca—. `NegotiableLocales` es el contrato de
     * `App\Support` que usa el middleware que negocia el idioma de cada
     * respuesta, y existe porque ese middleware esta **fuera de los modulos y no
     * puede nombrar un tipo de `Shared`** (Deptrac: `AppFramework` no alcanza
     * `App\Modules\*`, frontera intacta desde la tarea 0.2). Es la misma solucion
     * que ya usa `LicenseStateProbe` para el estado de licencia de `/health`.
     *
     * El segundo enlace apunta a la MISMA instancia. Dos adaptadores duplicarian
     * la consulta, la memoria y el respaldo, y el dia que divergieran la API
     * diria que ofrece un idioma y las respuestas saldrian en otro.
     *
     * `scoped()` como el resto: un cambio guardado en el panel rige en la
     * peticion siguiente y nunca hace falta reiniciar.
     */
    private function registerLocales(): void
    {
        // El adaptador se enlaza por su clase concreta y los dos contratos
        // apuntan a el: asi `scoped()` guarda UNA instancia y los dos lados
        // —el modulo y el armazon— comparten memoria y respaldo.
        $this->app->scoped(
            DbLocalePolicyProvider::class,
            static fn (Application $app): DbLocalePolicyProvider => new DbLocalePolicyProvider(
                $app->make(GetSettingsHandler::class),
                $app->make(ConfigRepository::class),
            ),
        );

        $this->app->scoped(
            LocalePolicyProvider::class,
            static fn (Application $app): DbLocalePolicyProvider => $app->make(DbLocalePolicyProvider::class),
        );

        $this->app->scoped(
            NegotiableLocales::class,
            static fn (Application $app): DbLocalePolicyProvider => $app->make(DbLocalePolicyProvider::class),
        );
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

        $this->registerSetupWizard();
    }

    /**
     * El asistente de puesta en marcha (tarea 5.5, RF-PD-03).
     *
     * Los dos adaptadores van sobre `DB::connection()` y no sobre un modelo
     * Eloquent: `setup_progress` es una tabla de nueve filas como mucho, y
     * {@see DatabaseSetupFacts} consulta el ESQUEMA de otros modulos —`users`,
     * `employees`, `credentials`, `devices`— sin nombrar ni una clase suya, que
     * es el criterio con el que ya vive {@see DatabasePlanUsageCounter}
     * (doc 02 §1.6).
     */
    private function registerSetupWizard(): void
    {
        $this->app->bind(
            SetupProgressRepository::class,
            static fn (): DatabaseSetupProgressRepository => new DatabaseSetupProgressRepository(DB::connection()),
        );

        $this->app->bind(
            SetupFacts::class,
            static fn (): DatabaseSetupFacts => new DatabaseSetupFacts(DB::connection()),
        );
    }

    public function boot(): void
    {
        /*
         * Zona del asistente de puesta en marcha: **10 r/m por origen**
         * (`GET /setup/status` y `POST /setup/administrator`).
         *
         * POR QUE UNA ZONA PROPIA Y NO `throttle:auth`. Aquella compone su clave
         * por cuenta con el `email` del cuerpo, y aqui no hay ninguna cuenta a la
         * que contar: la de `/setup/administrator` todavia no existe y la de
         * `/setup/status` no la hay. Con la zona de acceso, todo el trafico del
         * asistente compartiria el cubo de la cadena vacia —el mismo fallo que la
         * tarea 2.1 encontro en `/auth/2fa/*`—, y ademas gastaria el cupo de
         * acceso de la persona que esta a punto de entrar.
         *
         * SOLO POR ORIGEN, que es lo unico que hay. No hace falta mas: la unica
         * escritura publica deja de existir en cuanto se ejecuta con exito una
         * vez, asi que el techo no protege un recurso que se pueda agotar sino
         * el ruido de un escaner contra una instalacion recien montada.
         *
         * 10 R/M NO ES UNA MEDICION: la puesta en marcha la hace una persona,
         * una vez, y consulta el estado entre paso y paso. Deja margen de sobra
         * para eso y corta un bucle en el primer segundo. Es configuracion y no
         * una constante (regla dura 13).
         */
        RateLimiter::for('setup', static function (Request $request): array {
            $perMinute = max(1, Config::integer('product.setup_rate_limit_per_minute', 10));

            return [Limit::perMinute($perMinute)->by('setup-ip:'.(string) $request->ip())];
        });

        /*
         * Zona de la marca: **120 r/m por origen** (`GET /branding` y
         * `GET /branding/logo`, RF-PD-08).
         *
         * ZONA PROPIA Y NO `throttle:setup`. Aquella tiene 10 r/m porque protege
         * un acto que ocurre una vez en la vida de la instalacion; estas dos las
         * piden NAVEGADORES AL ARRANCAR —veinte tablets, el panel de recepcion y
         * los moviles de la plantilla entrando al portal, casi siempre detras de
         * una sola IP con NAT—, y diez por minuto se agotarian solos. Compartir
         * cubo ademas dejaria una puesta en marcha sin cupo por culpa del trafico
         * normal.
         *
         * 120 NO ES UNA MEDICION: es margen de sobra para el arranque simultaneo
         * de la plantilla de un hotel y sigue cortando un bucle en el primer
         * segundo. Y lo que protege no es un secreto —el nombre del hotel y su
         * color es lo mismo que lleva impreso cada tarjeta—: es un techo de
         * ruido, no un control de acceso. Es configuracion y no una constante
         * (regla dura 13).
         */
        RateLimiter::for('branding', static function (Request $request): array {
            $perMinute = max(1, Config::integer('product.branding_rate_limit_per_minute', 120));

            return [Limit::perMinute($perMinute)->by('branding-ip:'.(string) $request->ip())];
        });

        /*
         * `PUT /api/v1/setup/steps/{step}` y `POST /api/v1/setup/complete` son de
         * `admin` y de nadie mas: el asistente decide la zona horaria con la que
         * se atribuyen las jornadas (RN-05) y el perfil con el que se calcula el
         * cumplimiento (RL-21), que es la misma potestad que `/settings`.
         *
         * El sujeto es {@see SetupState} —«el asistente de esta instalacion»— y
         * no una fila de `setup_progress`: la policy no autoriza sobre una marca
         * concreta, y ademas puede no haber ninguna.
         *
         * **La creacion del primer administrador no pasa por ninguna policy**, y
         * no puede: ocurre cuando no hay ninguna cuenta a la que autorizar. Su
         * unica guarda es que no exista ninguna, y vive en `Identity`.
         */
        Gate::policy(SetupState::class, SetupPolicy::class);

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

        /*
         * Zona del paquete de diagnostico: **3 r/m por cuenta y por origen**
         * (`POST /api/v1/diagnostics/bundle`, RF-PD-09).
         *
         * ZONA PROPIA Y MUCHO MAS ESTRECHA QUE `throttle:management`, que tiene
         * 120. La diferencia no es de criterio, es de coste: **generar el
         * paquete recorre la instalacion entera**. Lee la plantilla, cuenta el
         * `audit_log`, abre un socket TLS contra el borde, mide dos sistemas de
         * ficheros y ejecuta las ocho familias de `doctor`. Con el techo de
         * gestion, un boton pulsado con impaciencia —o un panel con un reintento
         * mal puesto— pondria ciento veinte de esos recorridos por minuto sobre
         * la misma base de datos por la que pasa cada fichaje (ADR-010).
         *
         * POR CUENTA Y POR ORIGEN, como la zona de gestion. La cuenta es el eje
         * que importa aqui —un token de soporte y un administrador no deben
         * compartir cubo— y el origen es la red de seguridad para el caso en el
         * que el actor no se pueda resolver.
         *
         * 3 NO ES UNA MEDICION: generar el paquete es un acto deliberado que una
         * persona hace una vez, mira el fichero y envia. Tres deja margen para
         * equivocarse de opcion y repetir. Es configuracion y no una constante
         * (regla dura 13).
         */
        RateLimiter::for('diagnostics', static function (Request $request): array {
            $perMinute = max(1, Config::integer('product.diagnostics_rate_limit_per_minute', 3));
            $actor = $request->user();

            return [
                Limit::perMinute($perMinute)->by('diagnostics-ip:'.(string) $request->ip()),
                Limit::perMinute($perMinute)->by('diagnostics-account:'.(
                    $actor instanceof ManagementActor ? $actor->actorUuid() : 'desconocido'
                )),
            ];
        });

        /*
         * `POST /api/v1/diagnostics/bundle` es de `admin` y de nadie mas (Anexo
         * B del doc 01, §7.3: `diagnostics:*` es del administrador de
         * instalacion). El middleware comprueba el ambito y esta policy el rol
         * (regla dura 18).
         *
         * El sujeto es {@see DiagnosticsBundle} —«el paquete de diagnostico de
         * esta instalacion»— y no una fila: el paquete no se guarda en ningun
         * sitio, se genera y se entrega. Una policy sobre un modelo no tendria
         * nada que recibir.
         *
         * La policy tiene **dos** metodos y el segundo es el que convierte RL-19
         * en codigo: un token de soporte genera el paquete anonimizado y recibe
         * `403` si pide el que lleva datos de la plantilla.
         */
        Gate::policy(DiagnosticsBundle::class, DiagnosticsPolicy::class);

        /*
         * `throttle:data-export` (RF-PD-14, tarea 5.10).
         *
         * 30 POR MINUTO Y NO 3 COMO EL DIAGNOSTICO, y la diferencia no es un
         * descuido. El limitador cubre las tres rutas, y **el panel sondea
         * `GET /data-export` cada cinco segundos mientras dura la generacion**:
         * doce peticiones por minuto solo de sondeo. Con el techo del
         * diagnostico, la pantalla se bloquearia sola a los quince segundos de
         * pulsar el boton, y el cliente veria un `429` justo cuando el producto
         * esta haciendo lo que le pidio.
         *
         * LO QUE DE VERDAD PROTEGE A LA BASE DE DATOS NO ES ESTE NUMERO. Es el
         * indice unico parcial `data_exports_single_in_progress_uidx`: por muchas
         * veces que se pulse, solo puede haber una exportacion en curso, y el
         * recorrido completo de las tablas ocurre una sola vez. Este limite esta
         * para que un cliente HTTP mal escrito no convierta el sondeo en un bucle
         * cerrado.
         *
         * POR CUENTA Y POR ORIGEN, como la zona de gestion y como el
         * diagnostico: la cuenta es el eje que importa y el origen es la red de
         * seguridad para cuando el actor no se puede resolver. Es configuracion
         * y no una constante (regla dura 13).
         *
         * EL CUBO POR IP ES CUATRO VECES EL DE CUENTA, y ese factor arregla un
         * fallo real. En un hotel, recepcion, direccion y el despacho de RRHH
         * salen a internet por **la misma IP publica**: con el mismo techo en los
         * dos ejes, tres administradores con la pantalla abierta sondeando a doce
         * peticiones por minuto agotarian entre los tres el cubo compartido y se
         * cortarian unos a otros. Ese `429` no protege nada —el trabajo pesado ya
         * lo impide el indice unico— y el cliente lo lee como «el producto esta
         * roto». Cuatro deja sitio a la plantilla de administradores que cabe en
         * un hotel y sigue cerrando el bucle cerrado de un cliente HTTP mal
         * escrito, que es a lo unico que apunta el limite por origen.
         */
        RateLimiter::for('data-export', static function (Request $request): array {
            $perMinute = max(1, Config::integer('product.data_export_rate_limit_per_minute', 30));
            $actor = $request->user();

            return [
                Limit::perMinute($perMinute * self::DATA_EXPORT_IP_BUDGET_FACTOR)
                    ->by('data-export-ip:'.(string) $request->ip()),
                Limit::perMinute($perMinute)->by('data-export-account:'.(
                    $actor instanceof ManagementActor ? $actor->actorUuid() : 'desconocido'
                )),
            ];
        });

        /*
         * Las tres rutas de `/api/v1/data-export` son de `admin` **del cliente** y
         * de nadie mas (Anexo B del doc 01, §7.3 nota 6: `settings:*` es del
         * administrador de instalacion). El middleware comprueba el ambito y esta
         * policy comprueba el rol **y que quien pregunta no sea el fabricante**
         * (regla dura 18 y regla dura 16).
         *
         * El sujeto es el modelo de dominio {@see DataExportModel} —«la
         * exportacion integra de esta instalacion»— y no una fila: la policy no
         * autoriza sobre una exportacion concreta, porque todas son iguales ante
         * ella, y las tres operaciones existen antes de que haya ninguna.
         */
        Gate::policy(DataExportModel::class, DataExportPolicy::class);

        /*
         * Las tres rutas de `/api/v1/support/grants` son de `admin` **del
         * cliente** y de nadie mas (Anexo B del doc 01, §7.3: `support:*` es del
         * administrador de instalacion). El middleware comprueba el ambito y esta
         * policy comprueba el rol **y que quien pregunta no sea el propio
         * fabricante** (regla dura 18 y ADR-020).
         *
         * El sujeto es el modelo de dominio {@see SupportGrantModel} —«los
         * accesos de soporte de esta instalacion»— y no una fila: la policy no
         * autoriza sobre una concesion concreta, porque todas son iguales ante
         * ella, y las tres operaciones existen antes de que haya ninguna.
         */
        Gate::policy(SupportGrantModel::class, SupportGrantPolicy::class);

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
                /*
                 * Los dos del acceso de soporte (Anexo C, RF-PD-11, tarea 5.9).
                 *
                 * **Tampoco se programan**, y aqui importa mas: una tarea
                 * nocturna que concediera o revocara accesos por su cuenta seria
                 * lo contrario de «expreso» (ADR-020). Las concesiones caducan
                 * solas por su `expires_at`, sin que nada tenga que correr.
                 *
                 * Existen en consola porque el panel puede no estar disponible
                 * justo cuando hace falta soporte, y porque `support:revoke
                 * --all` es el boton de panico: cortar todo acceso del fabricante
                 * no puede depender de que la aplicacion web responda.
                 */
                SupportGrantCommand::class,
                SupportRevokeCommand::class,
                /*
                 * Los dos del diagnostico (Anexo C, RF-PD-09, RF-PD-13, tarea
                 * 5.9).
                 *
                 * **`product:doctor` es el unico comando del producto que
                 * ejecutan OTROS PROGRAMAS**: `install.sh` en su fase de
                 * verificacion y `update.sh` tras arrancar. De ahi que sus
                 * codigos de salida esten documentados en la cabecera de la
                 * clase y no se puedan cambiar sin tocar los dos scripts.
                 *
                 * Tampoco se programan. Un `doctor` nocturno que avisara por su
                 * cuenta necesitaria un destinatario, y el fabricante **no es
                 * destinatario de ninguna alerta** (ADR-020, doc 02 §9.3): no
                 * tiene acceso y no puede intervenir. Las alertas del cliente
                 * salen de Prometheus, que es de quien las mira.
                 */
                ProductDoctorCommand::class,
                ProductDiagnosticsCommand::class,
                /*
                 * La exportacion integra (RF-PD-14, RL-20, tarea 5.10).
                 *
                 * **La parte `--purge` se programa; el comando entero no.**
                 * `routes/console.php` ejecuta `product:export-all --purge` cada
                 * hora. Generar no se programa nunca —una copia completa de la
                 * plantilla que aparece sola en el disco cada noche seria lo
                 * contrario de lo que RL-20 quiere—; lo que se programa es
                 * BORRAR las que ya caducaron, porque la retencion de un fichero
                 * asi no puede depender de que alguien se acuerde.
                 *
                 * En consola ademas del panel porque el panel descarga el ZIP
                 * entero en la memoria del navegador: por encima de un giga
                 * conviene generarlo aqui y sacarlo con `docker compose cp`. Y
                 * porque RL-20 no puede depender de que la puerta de delante
                 * este abierta.
                 */
                ProductExportAllCommand::class,
                /*
                 * La telemetria opcional (Anexo C, RF-PD-12, tarea 5.10).
                 *
                 * **El unico que se programa entero**: `routes/console.php` lo
                 * ejecuta con `--send` los lunes a las 05:40 UTC, y eso es todo
                 * lo que hace el comando. De los ocho de este modulo, el de
                 * arriba tiene programada **solo su purga** y los seis restantes
                 * no se programan nunca.
                 *
                 * Sin banderas no envia nada ni deja rastro: imprime el
                 * documento que se enviaria, que es como la ficha quiere que el
                 * cliente decida -con la lista delante-.
                 *
                 * Vive aqui y no en `routes/console.php` por lo mismo que los
                 * demas: ahi vive **cuando** se ejecuta un comando, no que
                 * comandos existen.
                 */
                ProductTelemetryCommand::class,
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

    /**
     * `product:doctor` y el paquete de diagnostico (tarea 5.9, **RF-PD-09**,
     * **RF-PD-13**, ADR-020).
     *
     * ## Todo lo del entorno se resuelve AQUI, en el borde
     *
     * Rutas, umbrales, version, idioma y **el entorno del proceso entero** entran
     * ya resueltos en las sondas y en los recolectores. Es el mismo criterio que
     * el resto del modulo (regla dura 14), y aqui tiene un motivo extra: la
     * prueba que exige la ficha 5.9 —«ninguna clave secreta del `.env.example`
     * aparece en el paquete»— necesita poder pasarle al recolector un entorno
     * inventado. Con `env()` dentro del recolector, esa prueba no se podria
     * escribir sin ensuciar el proceso entero.
     *
     * `$_ENV` y no `getenv()`: el segundo devuelve tambien lo que herede el
     * proceso del sistema operativo, y el paquete solo debe describir **la
     * configuracion de esta aplicacion**.
     *
     * ## El orden de las sondas y de los recolectores es el del informe
     *
     * Y por eso es una lista literal y no un descubrimiento por reflexion. Una
     * persona con prisa lee el informe de arriba abajo: base de datos, colas,
     * correo, certificado, permisos, disco, aplicacion, configuracion y
     * licencia, de lo que impide fichar a lo que no impide nada. Que ese orden
     * dependiera del orden en que el sistema de ficheros devuelve unas clases
     * seria dejarlo al azar.
     *
     * El orden de las secciones del paquete es el del contrato, por lo mismo:
     * una respuesta que las devolviera en otro orden seguiria siendo valida,
     * pero dos paquetes del mismo cliente dejarian de poder compararse con
     * `diff`.
     *
     * ## `bind()` y no `singleton()`
     *
     * Nada de esto guarda estado y todo mide el mundo en el instante en que se
     * le pregunta. Un `singleton()` en un trabajador de cola congelaria el
     * espacio libre en disco de la primera vez que alguien ejecuto `doctor`.
     */
    private function registerDiagnostics(): void
    {
        $this->app->bind(
            DoctorTranslator::class,
            static fn (Application $app): LaravelDoctorTranslator => new LaravelDoctorTranslator(
                $app->make(Translator::class),
            ),
        );

        $this->app->bind(
            ServiceInspector::class,
            static fn (Application $app): ServiceInspector => new ServiceInspector(
                database: DB::connection(),
                redis: $app->make(Redis::class),
                migrationsPath: database_path('migrations'),
                // Los cuatro con la lectura tolerante y no con `Config::string()`
                // por lo mismo que el correo: esas ayudas **lanzan** si el tipo
                // no es exacto, y `broadcasting.default` puede estar sin definir
                // en una instalacion que no use tiempo real. Un diagnostico que
                // reventara por eso seria inutil justo cuando hace falta.
                queueConnection: self::text(Config::get('queue.default')) ?? 'sync',
                queueName: self::text(Config::get('queue.connections.redis.queue')) ?? 'default',
                realtimeEnabled: (bool) Config::get('realtime.enabled', true),
                broadcastConnection: self::text(Config::get('broadcasting.default')) ?? 'null',
            ),
        );

        $this->app->bind(
            RunDoctorHandler::class,
            static fn (Application $app): RunDoctorHandler => new RunDoctorHandler(
                probes: [
                    $app->make(DatabaseProbe::class),
                    $app->make(QueueProbe::class),
                    new MailProbe(
                        mailer: Config::string('mail.default'),
                        // `Config::string()` y `Config::integer()` no valen aqui:
                        // el `.env` entrega el puerto como texto y las dos
                        // lanzan si el tipo no es exacto. Un `doctor` que
                        // reventara por eso seria inutil justo cuando hace
                        // falta.
                        host: self::text(Config::get('mail.mailers.smtp.host')),
                        port: self::number(Config::get('mail.mailers.smtp.port')),
                        environment: Config::string('app.env'),
                    ),
                    new TlsProbe(
                        applicationUrl: Config::string('app.url'),
                        allowSelfSigned: Config::boolean('security.tls_allow_self_signed'),
                        clock: $app->make(Clock::class),
                    ),
                    new PermissionsProbe(
                        writablePaths: [storage_path(), base_path('bootstrap/cache')],
                        backupPath: Config::string('backup.path'),
                        brandingLogoRoot: Config::string('branding.logo_root'),
                        settings: $app->make(GetSettingsHandler::class),
                        logos: $app->make(LogoInspector::class),
                    ),
                    new DiskProbe(
                        storagePath: storage_path(),
                        backupPath: Config::string('backup.path'),
                    ),
                    new ApplicationProbe(
                        timezone: Config::string('app.timezone'),
                        debug: Config::boolean('app.debug'),
                        environment: Config::string('app.env'),
                    ),
                    new SettingsProbe(
                        settings: $app->make(GetSettingsHandler::class),
                        environment: $_ENV,
                    ),
                    $app->make(LicenseProbe::class),
                ],
                translator: $app->make(DoctorTranslator::class),
                clock: $app->make(Clock::class),
                productVersion: Config::string('app.version'),
            ),
        );

        $this->app->bind(
            DiagnosticsBundleWriter::class,
            static fn (): JsonDiagnosticsBundleWriter => new JsonDiagnosticsBundleWriter(
                Config::string('product.diagnostics_storage_path'),
            ),
        );

        $this->app->bind(
            GenerateDiagnosticsBundleHandler::class,
            static fn (Application $app): GenerateDiagnosticsBundleHandler => new GenerateDiagnosticsBundleHandler(
                collectors: [
                    new InstallationCollector(
                        database: DB::connection(),
                        settings: $app->make(GetSettingsHandler::class),
                        profiles: $app->make(ComplianceProfileRepository::class),
                        productVersion: Config::string('app.version'),
                        environment: Config::string('app.env'),
                        applicationTimezone: Config::string('app.timezone'),
                    ),
                    new ConfigurationCollector(
                        settings: $app->make(GetSettingsHandler::class),
                        environment: $_ENV,
                    ),
                    $app->make(ServicesCollector::class),
                    $app->make(DoctorCollector::class),
                    $app->make(LicenseCollector::class),
                    new KioskCollector(DB::connection()),
                    new ErrorEventsCollector,
                    $app->make(MetricsCollector::class),
                    new UpdatesCollector(Config::string('backup.path')),
                    $app->make(AuditCollector::class),
                    new PersonalDataCollector(DB::connection(), $app->make(Clock::class)),
                ],
                events: $app->make(ProductEventPublisher::class),
                clock: $app->make(Clock::class),
                productVersion: Config::string('app.version'),
                maxBytes: Config::integer('product.diagnostics_max_bytes'),
            ),
        );
    }

    /**
     * Un valor de configuracion como texto, o nulo si no lo hay.
     *
     * Existe porque `Config::string()` **lanza** si el tipo no es exacto, y la
     * configuracion de correo llega del `.env` con lo que el cliente haya
     * escrito. `doctor` es lo que se ejecuta cuando algo esta mal configurado:
     * un comando que reventara al leer una configuracion rara seria inutil
     * justo en el momento en el que hace falta.
     */
    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** Lo mismo para un entero. Ver {@see self::text()}. */
    private static function number(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
