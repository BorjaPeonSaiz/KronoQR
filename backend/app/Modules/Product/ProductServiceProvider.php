<?php

declare(strict_types=1);

namespace App\Modules\Product;

use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Application\Port\SettingsAnomalyReporter;
use App\Modules\Product\Application\Port\SettingsMetrics;
use App\Modules\Product\Application\Port\SettingsRepository;
use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Domain\ValueObject\ResolvedSettings;
use App\Modules\Product\Http\Policy\SettingsPolicy;
use App\Modules\Product\Infrastructure\Adapter\CachedSettingsRepository;
use App\Modules\Product\Infrastructure\Adapter\DbBrandingProvider;
use App\Modules\Product\Infrastructure\Adapter\DbCompliancePolicyProvider;
use App\Modules\Product\Infrastructure\Adapter\DbOperationalSettingsProvider;
use App\Modules\Product\Infrastructure\Adapter\LaravelProductEventPublisher;
use App\Modules\Product\Infrastructure\Adapter\LoggingSettingsAnomalyReporter;
use App\Modules\Product\Infrastructure\Metrics\RedisSettingsMetrics;
use App\Modules\Product\Infrastructure\Persistence\EloquentSettingsRepository;
use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\CompliancePolicyProvider;
use App\Modules\Shared\Application\Port\OperationalSettingsProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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
            static fn (): DbCompliancePolicyProvider => new DbCompliancePolicyProvider(DB::connection()),
        );

        $this->app->bind(
            ProductEventPublisher::class,
            static fn (Application $app): LaravelProductEventPublisher => new LaravelProductEventPublisher(
                $app->make(Dispatcher::class),
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
    }
}
