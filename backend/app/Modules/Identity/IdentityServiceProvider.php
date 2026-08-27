<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\EmployeeDirectory;
use App\Modules\Identity\Application\Port\AccessTokenIssuer;
use App\Modules\Identity\Application\Port\CardRenderer;
use App\Modules\Identity\Application\Port\CredentialMetrics;
use App\Modules\Identity\Application\Port\CredentialRepository;
use App\Modules\Identity\Application\Port\CredentialSecretFactory;
use App\Modules\Identity\Application\Port\DeviceRepository;
use App\Modules\Identity\Application\Port\DeviceTokenIssuer;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Application\Port\LoginAttempts;
use App\Modules\Identity\Application\Port\QrKeyProvider;
use App\Modules\Identity\Application\Port\UserAccounts;
use App\Modules\Identity\Application\Support\CredentialTelemetry;
use App\Modules\Identity\Application\UseCase\DeliverCredential;
use App\Modules\Identity\Application\UseCase\IssueDeviceToken;
use App\Modules\Identity\Application\UseCase\MintCards;
use App\Modules\Identity\Application\UseCase\RotateDeviceTokenIfDue;
use App\Modules\Identity\Domain\Model\Credential;
use App\Modules\Identity\Domain\ValueObject\DeviceStatus;
use App\Modules\Identity\Http\Policy\CredentialPolicy;
use App\Modules\Identity\Infrastructure\Adapter\BrowsershotCardRenderer;
use App\Modules\Identity\Infrastructure\Adapter\CacheLoginAttempts;
use App\Modules\Identity\Infrastructure\Adapter\ConfiguredQrKeyProvider;
use App\Modules\Identity\Infrastructure\Adapter\EloquentCredentialFingerprints;
use App\Modules\Identity\Infrastructure\Adapter\EndroidQrEncoder;
use App\Modules\Identity\Infrastructure\Adapter\HmacSignatureVerifier;
use App\Modules\Identity\Infrastructure\Adapter\LaravelIdentityEventPublisher;
use App\Modules\Identity\Infrastructure\Adapter\RandomCredentialSecretFactory;
use App\Modules\Identity\Infrastructure\Adapter\SanctumAccessTokenIssuer;
use App\Modules\Identity\Infrastructure\Adapter\SanctumDeviceTokenIssuer;
use App\Modules\Identity\Infrastructure\Console\CreateManagementUserCommand;
use App\Modules\Identity\Infrastructure\Console\CredentialStatusCommand;
use App\Modules\Identity\Infrastructure\Console\DeliverCredentialCommand;
use App\Modules\Identity\Infrastructure\Console\IssueCredentialCommand;
use App\Modules\Identity\Infrastructure\Console\PrintCredentialBatchCommand;
use App\Modules\Identity\Infrastructure\Console\PrintCredentialCommand;
use App\Modules\Identity\Infrastructure\Console\RevokeCredentialCommand;
use App\Modules\Identity\Infrastructure\Metrics\TextfileCredentialMetrics;
use App\Modules\Identity\Infrastructure\Persistence\Device;
use App\Modules\Identity\Infrastructure\Persistence\EloquentCredentialRepository;
use App\Modules\Identity\Infrastructure\Persistence\EloquentDeviceRepository;
use App\Modules\Identity\Infrastructure\Persistence\EloquentUserAccounts;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\CredentialFingerprints;
use App\Modules\Shared\Application\Port\EmployeeRegistry;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Psr\Log\LoggerInterface;

/**
 * Modulo Identity — usuarios, roles, permisos, credenciales QR y tokens de
 * dispositivo (doc 02 §1.6). Depende de Shared y de
 * Attendance/Application/Port, cuyo puerto implementa.
 *
 * **Aqui esta la arista de ADR-025 de este modulo:**
 *
 *   Attendance\Application\Port\CredentialResolver -> HmacSignatureVerifier
 *
 * El adaptador vive en `Identity/Infrastructure/Adapter/`, que es donde estan la
 * tabla `credentials` y las claves de firma, y el enlace se declara **en este
 * proveedor y no en el de `Attendance`** (ADR-025, restriccion 3): el nucleo no
 * sabe quien le resuelve la credencial, y por eso rotar el esquema de firma no
 * toca ni una linea del fichaje.
 *
 * **Las policies se registran contra los modelos de DOMINIO**, no contra los
 * modelos Eloquent, por lo mismo que en `Workforce`: si la autorizacion se
 * declarara sobre la fila, habria que cargarla para poder preguntar si se puede
 * ver, y esa es la via por la que la autorizacion acaba ocurriendo despues del
 * acceso a los datos.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UserAccounts::class, EloquentUserAccounts::class);
        $this->app->bind(AccessTokenIssuer::class, SanctumAccessTokenIssuer::class);

        $this->app->bind(
            LoginAttempts::class,
            static fn ($app): CacheLoginAttempts => new CacheLoginAttempts($app->make(RateLimiter::class)),
        );

        $this->registerCredentials();
        $this->registerDevices();
    }

    public function boot(): void
    {
        $this->registerAuthenticationRateLimiter();
        $this->rejectTokensOfDeactivatedAccounts();

        Gate::policy(Credential::class, CredentialPolicy::class);

        if ($this->app->runningInConsole()) {
            // Los seis del Anexo C del doc 02 que ya existen. Falta
            // `credentials:rotate-key`, que completa su flujo en la tarea 2.12 y
            // no se declara aqui para que su ausencia sea visible.
            $this->commands([
                CreateManagementUserCommand::class,
                IssueCredentialCommand::class,
                PrintCredentialCommand::class,
                PrintCredentialBatchCommand::class,
                DeliverCredentialCommand::class,
                CredentialStatusCommand::class,
                RevokeCredentialCommand::class,
            ]);
        }
    }

    /**
     * Credenciales QR (RF-QR-01..03, ADR-005).
     *
     * El proveedor de claves es **singleton** porque memoriza el llavero:
     * decodificar dos claves base64 en cada escaneo no aporta nada y este es el
     * camino mas caliente del producto.
     */
    private function registerCredentials(): void
    {
        $this->app->bind(CredentialRepository::class, EloquentCredentialRepository::class);

        // El padron cacheable del quiosco necesita el hash del token de cada
        // tarjeta activa (RF-KI-03, tarea 1.7). El puerto lo declara `Shared`
        // porque quien lo necesita —`Kiosk`— y quien lo tiene —este modulo— son
        // dos satelites y ninguno puede importar al otro (§1.6). Es el hash, nunca
        // el token: el token en claro no existe fuera del momento de imprimir
        // (ADR-034).
        $this->app->bind(CredentialFingerprints::class, EloquentCredentialFingerprints::class);
        $this->app->bind(CredentialSecretFactory::class, RandomCredentialSecretFactory::class);
        $this->app->singleton(QrKeyProvider::class, ConfiguredQrKeyProvider::class);

        $this->app->bind(
            IdentityEventPublisher::class,
            static fn (Application $app): LaravelIdentityEventPublisher => new LaravelIdentityEventPublisher(
                $app->make(Dispatcher::class),
            ),
        );

        // ADR-025: el puerto lo declara `Attendance`, que es quien lo necesita, y
        // lo implementa este modulo, que es quien tiene la tabla y la clave.
        //
        // El suelo de tiempo de los rechazos llega YA RESUELTO desde la
        // configuracion (RS-03): el adaptador no pregunta por el, lo recibe. Es
        // lo que permite que una prueba lo ponga a cero para medir el trabajo
        // real y otra lo deje en su valor para comprobar el suelo.
        $this->app->bind(
            CredentialResolver::class,
            static fn (Application $app): HmacSignatureVerifier => new HmacSignatureVerifier(
                keys: $app->make(QrKeyProvider::class),
                credentials: $app->make(CredentialRepository::class),
                employees: $app->make(EmployeeRegistry::class),
                directory: $app->make(EmployeeDirectory::class),
                rejectionFloorMs: max(0, Config::integer('identity.credentials.rejection_floor_ms', 25)),
            ),
        );

        $this->registerCardPrinting();
    }

    /**
     * La tarjeta impresa (RF-QR-04..06, RF-QR-08, tarea 1.10).
     *
     * **El nivel de correccion de errores llega ya resuelto** desde la
     * configuracion, igual que el suelo de tiempo de los rechazos y que los
     * umbrales legales (regla dura 14): el codificador no consulta `config()`.
     * Es lo que permite que una prueba lo fije y compruebe que un QR de nivel `Q`
     * es distinto de uno de nivel `L`.
     *
     * **`MintCards` se declara explicitamente y no se deja al autowiring** porque
     * es la pieza que sostiene el orden de los seis pasos de ADR-034 y sus siete
     * colaboradores son exactamente los que necesita: si alguien le anadiera un
     * octavo, tendria que pasar por aqui.
     */
    private function registerCardPrinting(): void
    {
        $this->app->bind(
            CredentialTelemetry::class,
            static fn (Application $app): CredentialTelemetry => new CredentialTelemetry(
                $app->make(LoggerInterface::class),
            ),
        );

        $this->app->bind(
            CardRenderer::class,
            static fn (): BrowsershotCardRenderer => new BrowsershotCardRenderer(
                new EndroidQrEncoder(
                    Config::string('identity.credentials.card.error_correction', 'Q'),
                ),
            ),
        );

        $this->app->bind(CredentialMetrics::class, TextfileCredentialMetrics::class);

        $this->app->bind(
            MintCards::class,
            static fn (Application $app): MintCards => new MintCards(
                credentials: $app->make(CredentialRepository::class),
                keys: $app->make(QrKeyProvider::class),
                secrets: $app->make(CredentialSecretFactory::class),
                renderer: $app->make(CardRenderer::class),
                events: $app->make(IdentityEventPublisher::class),
                clock: $app->make(Clock::class),
                connection: DB::connection(),
            ),
        );

        $this->app->bind(
            DeliverCredential::class,
            static fn (Application $app): DeliverCredential => new DeliverCredential(
                credentials: $app->make(CredentialRepository::class),
                employees: $app->make(EmployeeRegistry::class),
                events: $app->make(IdentityEventPublisher::class),
                clock: $app->make(Clock::class),
                connection: DB::connection(),
                telemetry: $app->make(CredentialTelemetry::class),
            ),
        );
    }

    /**
     * Tokens de dispositivo (RF-ID-04, RS-04, §7.3).
     *
     * La vida del token y el umbral de rotacion se resuelven aqui y entran en el
     * caso de uso como valores: `Application` no consulta la configuracion, del
     * mismo modo que el dominio no consulta los umbrales legales (regla dura
     * 14).
     */
    private function registerDevices(): void
    {
        $this->app->bind(DeviceRepository::class, EloquentDeviceRepository::class);
        $this->app->bind(DeviceTokenIssuer::class, SanctumDeviceTokenIssuer::class);

        $this->app->bind(
            IssueDeviceToken::class,
            static fn (Application $app): IssueDeviceToken => new IssueDeviceToken(
                devices: $app->make(DeviceRepository::class),
                tokens: $app->make(DeviceTokenIssuer::class),
                events: $app->make(IdentityEventPublisher::class),
                clock: $app->make(Clock::class),
                connection: DB::connection(),
                lifetimeDays: Config::integer('identity.devices.token_days', 90),
            ),
        );

        $this->app->bind(
            RotateDeviceTokenIfDue::class,
            static fn (Application $app): RotateDeviceTokenIfDue => new RotateDeviceTokenIfDue(
                devices: $app->make(DeviceRepository::class),
                tokens: $app->make(DeviceTokenIssuer::class),
                issue: $app->make(IssueDeviceToken::class),
                clock: $app->make(Clock::class),
                rotationThreshold: Config::float('identity.devices.token_rotation_threshold', 0.8),
            ),
        );
    }

    /**
     * Zona de autenticacion del §7.1: **5 r/m**.
     *
     * Se limita por correo **y** por IP a la vez, no solo por IP: en un hotel
     * todo el personal de gestion sale por la misma linea, y un limite por IP
     * dejaria a una recepcion entera compartiendo cinco intentos por minuto.
     * Limitar solo por correo seria peor: bastaria con rotar correos.
     *
     * Nginx aplica ademas su propio limite en el borde (§7.2). Los dos, porque
     * el del borde no distingue cuentas y este no ve el trafico que nunca llega
     * a PHP.
     */
    private function registerAuthenticationRateLimiter(): void
    {
        RateLimiterFacade::for('auth', static function (Request $request): array {
            $email = mb_strtolower($request->string('email')->trim()->value());

            return [
                Limit::perMinute(5)->by('auth-ip:'.(string) $request->ip()),
                Limit::perMinute(5)->by('auth-account:'.$email),
            ];
        });
    }

    /**
     * Una cuenta desactivada deja de valer **de inmediato**, sin esperar a que
     * caduque su token.
     *
     * Es la mitad que se olvida de la baja de un usuario: se marca `is_active`
     * a `false` y la sesion abierta en una tablet sigue funcionando hasta que
     * expira. Con esto, la baja tiene efecto en la peticion siguiente.
     */
    private function rejectTokensOfDeactivatedAccounts(): void
    {
        Sanctum::authenticateAccessTokensUsing(
            static function (HasAbilities $accessToken, bool $isValid): bool {
                if (! $isValid) {
                    return false;
                }

                $owner = $accessToken instanceof PersonalAccessToken
                    ? $accessToken->tokenable
                    : null;

                if ($owner instanceof User) {
                    return $owner->is_active;
                }

                // Un token de dispositivo (RF-ID-04) no cuelga de `users`: su
                // revocacion es la de su fila. Y tiene que valer YA, no dentro de
                // 90 dias: es la respuesta a una tablet robada, y si dependiera
                // de la caducidad del token esa tablet seguiria fichando tres
                // meses (RS-04).
                if ($owner instanceof Device) {
                    return $owner->status === DeviceStatus::ACTIVE->value;
                }

                // Falla cerrado, no abierto (revision de seguridad de la tarea
                // 1.5). Un tokenable que este metodo no reconoce es uno cuyo
                // estado no se ha comprobado: aceptarlo por defecto reintroduce
                // el fallo que este metodo existe para cerrar el dia que aparezca
                // un tercer tokenable (el portal del empleado de ADR-015 es el
                // candidato).
                return false;
            }
        );
    }
}
