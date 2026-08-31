<?php

declare(strict_types=1);

use App\Support\Version\DeployedVersion;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Version desplegada
    |--------------------------------------------------------------------------
    |
    | Lo que publica `GET /api/v1/health` (doc 02 §10.5): es lo que permite
    | correlacionar una incidencia con una version concreta sin entrar por SSH
    | al servidor del cliente. NO es la version del framework —eso es
    | `app()->version()`—, sino la del producto.
    |
    | El orden de resolucion y su porque estan en DeployedVersion. En resumen:
    | manda el entorno si trae un SemVer, y si no, el fichero VERSION del
    | repositorio.
    |
    | Las tres rutas candidatas del fichero son los tres sitios donde puede
    | correr este proceso, y no sobra ninguna:
    |
    |   base_path('VERSION')     la imagen de produccion, que lo copia junto a
    |                            la aplicacion (infra/docker/php/Dockerfile).
    |   base_path('../VERSION')  el arbol de fuentes, donde backend/ cuelga de
    |                            la raiz del repositorio (la CI y el host).
    |   /var/www/repo/VERSION    el contenedor de desarrollo, donde /var/www/html
    |                            es solo backend/ y la raiz llega montada de
    |                            solo lectura (infra/compose.dev.yaml).
    |
    */

    'version' => DeployedVersion::resolve(
        [env('APP_VERSION'), env('IMAGE_TAG')],
        [base_path('VERSION'), base_path('../VERSION'), '/var/www/repo/VERSION'],
    ),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Regla dura 3: todo instante se almacena en UTC y el proceso vive en UTC.
    | El valor NO se lee del entorno a proposito: APP_TIMEZONE=UTC esta en el
    | .env como documentacion, pero un cliente que lo cambiara por su zona
    | local invalidaria el registro horario en silencio. La zona de
    | presentacion es un atributo de cada centro (sites.timezone), y la
    | conversion ocurre solo en la capa de presentacion (RN-04, RN-05, ADR-004).
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    /*
    | KronoQR: idiomas en los que la API responde a una persona (regla dura 13,
    | RF-PD-01). `NegotiateLocale` elige entre estos con `Accept-Language`; fuera
    | de la lista, o sin cabecera, responde en `locale`. Los documentos (CSV,
    | XLSX, PDF) no negocian y salen siempre en `locale`. Que exista `lang/xx`
    | no basta para ofrecer un idioma: lo decide esta lista. La tarea 5.8 la
    | lleva a `installation_settings`.
    */
    'supported_locales' => array_values(array_filter(
        array_map(trim(...), explode(',', (string) env('APP_SUPPORTED_LOCALES', 'es,en'))),
        static fn (string $locale): bool => $locale !== '',
    )),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
