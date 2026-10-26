<?php

declare(strict_types=1);

namespace App\Modules\Shared;

use App\Modules\Shared\Application\Port\AuthenticationMetrics;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\DeployedVersionProvider;
use App\Modules\Shared\Application\Port\GeneratedFileMetrics;
use App\Modules\Shared\Application\Port\GeneratedFileStore;
use App\Modules\Shared\Application\Port\PdfRenderingMetrics;
use App\Modules\Shared\Application\Port\PdfRenderingSlots;
use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Application\Port\SealedPinOpener;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Application\Port\UseCaseMetrics;
use App\Modules\Shared\Application\Port\UuidGenerator;
use App\Modules\Shared\Application\Support\ConstantTimeFloor;
use App\Modules\Shared\Application\Support\UseCaseMetricsRegistry;
use App\Modules\Shared\Infrastructure\Adapter\CachePinAttempts;
use App\Modules\Shared\Infrastructure\Adapter\ConfigDeployedVersionProvider;
use App\Modules\Shared\Infrastructure\Adapter\RedisPdfRenderingSlots;
use App\Modules\Shared\Infrastructure\Adapter\SodiumSealedPinOpener;
use App\Modules\Shared\Infrastructure\Adapter\SystemClock;
use App\Modules\Shared\Infrastructure\Adapter\TimeOrderedUuidGenerator;
use App\Modules\Shared\Infrastructure\GeneratedFiles\FilesystemGeneratedFileStore;
use App\Modules\Shared\Infrastructure\Metrics\RedisAuthenticationMetrics;
use App\Modules\Shared\Infrastructure\Metrics\RedisGeneratedFileMetrics;
use App\Modules\Shared\Infrastructure\Metrics\RedisPdfRenderingMetrics;
use App\Modules\Shared\Infrastructure\Metrics\RedisUseCaseMetrics;
use App\Modules\Shared\Infrastructure\Persistence\ChainLockedWrite;
use App\Modules\Shared\Infrastructure\Persistence\DatabaseResponsibleAccounts;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Modulo Shared — objetos de valor comunes, tipos base y contratos
 * transversales (doc 02 §1.6). No depende de ningun otro modulo.
 *
 * Enlaces pendientes de las tareas que los declaran:
 *   - CompliancePolicyProvider  -> Product/Infrastructure/Adapter (ADR-025)
 *   - OperationalSettingsProvider -> Product/Infrastructure/Adapter (ADR-025)
 * Los declara este modulo y los enlaza ProductServiceProvider, que es quien
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

        // Los identificadores de los registros nuevos (RV-3). Un UUID v7 lleva
        // la hora dentro: por eso entra por un puerto, como el reloj, y no con
        // `Str::uuid7()` en cada caso de uso.
        $this->app->singleton(UuidGenerator::class, TimeOrderedUuidGenerator::class);

        // La version desplegada. Vive aqui porque la leen `Kiosk` —la
        // salud de cada tablet y el `minimum_app_version` del latido— y
        // `Product` —la sonda de version de `product:doctor`—, que no pueden
        // importarse entre si. El dato es de configuracion, no de un modulo.
        $this->app->singleton(DeployedVersionProvider::class, ConfigDeployedVersionProvider::class);

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
        // abre al fichar y `Kiosk` publica su clave publica en el
        // padron para que la tablet pueda cerrarlo sin red.
        $this->app->singleton(SealedPinOpener::class, SodiumSealedPinOpener::class);

        // `kronoqr_auth_attempts_total{channel,outcome}` (OWASP A09, §8.2). Vive
        // aqui, y no en `Identity`, porque los tres canales que lo alimentan
        // —panel, portal y PIN del quiosco— salen de tres modulos que no pueden
        // importarse entre si, y una metrica contada por tres adaptadores
        // distintos serian tres series con el mismo nombre y distinto criterio.
        $this->app->singleton(AuthenticationMetrics::class, RedisAuthenticationMetrics::class);

        // `use_case_duration_seconds{use_case,outcome}` (doc 02 §8). Los casos de
        // uso no reciben el puerto por constructor —son `final`, sin interfaz y
        // se construyen a mano en las pruebas—: lo encuentran en el registro
        // estatico, que se rellena con un resolutor PEREZOSO para no tocar Redis
        // hasta la primera medicion y para que cada aplicacion que arranca
        // sustituya a la anterior.
        $this->app->singleton(UseCaseMetrics::class, RedisUseCaseMetrics::class);
        $app = $this->app;
        UseCaseMetricsRegistry::resolveWith(static fn (): UseCaseMetrics => $app->make(UseCaseMetrics::class));

        // El orden de candados de toda escritura que se audita (ADR-010,
        // ADR-027). Vive aqui, y no en `Compliance`, porque lo
        // necesita `Attendance` —la reconciliacion de `daily_totals` corrige una
        // fila y deja asiento— y `Attendance` no puede importar `Compliance`
        // (doc 02 §1.6). La clave del candado es la MISMA que usa el escritor de
        // la cadena, y por eso vive en una sola clase: dos claves serian dos
        // ordenes de adquisicion y el mismo abrazo mortal por otra puerta.
        $this->app->singleton(SerializedLedgerWrite::class, ChainLockedWrite::class);

        // El suelo de tiempo de todo camino de rechazo (RS-03).
        // Vive aqui por el mismo motivo que el contador de intentos: lo aplican
        // dos modulos que no pueden importarse entre si —la resolucion de una
        // credencial en `Identity` y la recogida de un emparejamiento en
        // `Kiosk`— y con una copia en cada uno las dos habrian divergido en la
        // primera correccion. **Un solo umbral**, `security.rejection_floor_ms`:
        // dos numeros para el mismo control acaban con uno de ellos a cero por
        // descuido, y el sintoma es un camino que deja de estar protegido sin que
        // ninguna prueba del otro lo note.
        $this->app->singleton(
            ConstantTimeFloor::class,
            static fn (): ConstantTimeFloor => new ConstantTimeFloor(
                max(0, Config::integer('security.rejection_floor_ms', 25)),
            ),
        );

        // Los ficheros que genera el producto en el volumen `app-storage`
        // (ADR-045). Vive aqui porque los purgan tres modulos que no pueden
        // importarse entre si —`Product`, `Reporting` y `Compliance`— y el
        // confinamiento (ADR-045 C3) tiene que ser el MISMO para los tres: una copia por
        // modulo seria tres sitios donde olvidarse de no seguir un enlace.
        $this->app->singleton(
            GeneratedFileStore::class,
            static fn (Application $app): FilesystemGeneratedFileStore => new FilesystemGeneratedFileStore(
                $app->make(LoggerInterface::class),
            ),
        );
        $this->app->singleton(GeneratedFileMetrics::class, RedisGeneratedFileMetrics::class);

        // El tope de Chromium simultaneos de TODA la instalacion:
        // informes y registro propio (`Reporting`), tarjetas y hoja de
        // instrucciones (`Identity`). Uno solo, porque protege la memoria del
        // servidor; la espera la pone cada llamada. La configuracion sigue en
        // `reporting.pdf` para no cambiar las variables del `.env`.
        $this->app->bind(PdfRenderingMetrics::class, RedisPdfRenderingMetrics::class);
        $this->app->bind(
            PdfRenderingSlots::class,
            static fn (Application $app): RedisPdfRenderingSlots => new RedisPdfRenderingSlots(
                $app->make(Redis::class),
                $app->make(LoggerInterface::class),
                $app->make(PdfRenderingMetrics::class),
                Config::integer('reporting.pdf.max_concurrent'),
            ),
        );

        // La cuenta que firma una accion de consola con relevancia legal: la
        // revision de una purga (`Compliance`) y la correccion de la zona del
        // centro (`Workforce`, RN-23). Por la conexion de la aplicacion, la
        // misma de `SerializedLedgerWrite`, porque se pregunta con la cadena
        // tomada.
        $this->app->bind(
            ResponsibleAccounts::class,
            static fn (): DatabaseResponsibleAccounts => new DatabaseResponsibleAccounts(DB::connection()),
        );
    }
}
