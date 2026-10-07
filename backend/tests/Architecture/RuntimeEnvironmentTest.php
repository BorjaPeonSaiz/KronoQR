<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\SettingKey;
use Tests\Architecture\Support\ComposeEnvironment;
use Tests\Architecture\Support\DeclaredEnvironment;
use Tests\Architecture\Support\Repo;

/*
 * EL ENTORNO DEL RUNTIME, VARIABLE A VARIABLE (ADR-042 §3 y §4, hallazgo AUD-1).
 *
 * Hasta la 2.1.0, `app`, `horizon`, `reverb`, `scheduler` y `nginx` recibian
 * `env_file: .env` completo, y con el la contraseña del migrador, que es
 * SUPERUSER: quien ejecutara codigo PHP podia reescribir `shift_entries` y
 * `audit_log` y recalcular la cadena. ADR-042 lo cierra por el entorno: cada
 * servicio de runtime nombra las variables que necesita y solo `migrate` y
 * `restore`, de un solo uso, reciben `DB_MIGRATION_*`.
 *
 * Esta prueba guarda las dos mitades de esa decision:
 *
 *   · CONFINAMIENTO. Ninguna credencial capaz de alterar el registro llega a un
 *     contenedor que corre con la aplicacion en marcha, ni por nombre ni
 *     interpolada en otra variable.
 *   · COMPLETITUD. Quitar `env_file` convierte cada variable olvidada en un
 *     fallo SILENCIOSO: el cliente la pone en su `.env`, Compose no la entrega
 *     y la aplicacion sigue con el valor por defecto del codigo. Por eso cada
 *     clave de `.env.example`, cada `env()` de la configuracion y cada clave de
 *     `installation_settings` tiene que llegar a su servicio o figurar en una
 *     lista de exclusion de este fichero CON SU MOTIVO.
 *
 * Se lee el YAML ya compuesto (anclas y `<<` resueltos) con
 * `ComposeEnvironment`; ver ese fichero para el porque. La otra mitad de
 * ADR-042 §5 —que ningun fichero de `backend/app` nombre `pgsql_migrator`— esta
 * en `RuntimeDatabaseCredentialsTest`.
 */

const RUNTIME_ENVIRONMENT_PROD = 'infra/compose.prod.yaml';

const RUNTIME_ENVIRONMENT_DEV = 'infra/compose.dev.yaml';

/** Los contenedores que corren con la aplicacion en marcha (ADR-042 §4). */
const RUNTIME_ENVIRONMENT_SERVICES = ['app', 'horizon', 'reverb', 'scheduler', 'nginx'];

/**
 * Lo que ningun servicio de runtime puede nombrar, con el motivo.
 *
 * Las URL estan porque una URL de conexion lleva el rol y la contraseña dentro
 * y esquiva cualquier comprobacion por nombre; las `*_CONNECTION`, porque
 * reapuntarian una conexion del runtime a la de otro rol.
 */
const RUNTIME_ENVIRONMENT_FORBIDDEN = [
    'DB_MIGRATION_PASSWORD' => 'contraseña de fichaje_migrator, SUPERUSER del cluster: solo postgres, migrate y restore',
    'DB_MIGRATION_URL' => 'URL del migrador: lleva su credencial dentro',
    'DB_MIGRATION_CONNECTION' => 'reapuntaria las migraciones del runtime a otra conexion; el runtime no migra',
    'DB_MAINTENANCE_PASSWORD' => 'rol de purga: no vive en ningun contenedor, se aporta con -e al ejecutar la purga (ADR-027)',
    'DB_MAINTENANCE_URL' => 'URL del rol de purga: lleva su credencial dentro',
    'DB_MAINTENANCE_CONNECTION' => 'reapuntaria la purga a otra conexion',
    'POSTGRES_PASSWORD' => 'contraseña de arranque del cluster, que es la del migrador',
    'DB_URL' => 'una URL puede llevar cualquier rol dentro; el runtime se conecta con DB_HOST, DB_USERNAME y DB_PASSWORD',
    'DATABASE_URL' => 'lo mismo que DB_URL con otro nombre: una URL de conexion lleva rol y contraseña dentro',
    'PGPASSFILE' => 'libpq leeria las credenciales de un fichero y esquivaria cualquier comprobacion por nombre de variable',
    'PGSERVICEFILE' => 'un fichero de servicios de libpq puede llevar host, rol y contraseña',
];

/** El rol de copia (solo lectura) y la clave de cifrado de las copias: solo el planificador (ADR-042 §2). */
const RUNTIME_ENVIRONMENT_SCHEDULER_ONLY = ['BACKUP_DB_USERNAME', 'BACKUP_DB_PASSWORD', 'BACKUP_ENCRYPTION_KEY', 'BACKUP_ENCRYPTION_KEY_PREVIOUS'];

/**
 * NOMBRES de rol, nunca contraseñas: la configuracion los usa para escribir los
 * GRANT (`database.roles`, `AuditLogSchema`). Solo donde corre la aplicacion con
 * base de datos.
 */
const RUNTIME_ENVIRONMENT_ROLE_NAMES = ['DB_MIGRATION_USERNAME', 'DB_MAINTENANCE_USERNAME'];

/** Servicios de runtime que pueden recibir los nombres de rol. */
const RUNTIME_ENVIRONMENT_ROLE_NAME_SERVICES = ['app', 'horizon', 'scheduler'];

/** Secretos de la aplicacion. nginx no necesita ninguno (PIN-10). */
const RUNTIME_ENVIRONMENT_APP_SECRETS = [
    'APP_KEY', 'APP_PREVIOUS_KEYS', 'DB_PASSWORD', 'REDIS_PASSWORD', 'REVERB_APP_SECRET',
    'QR_SIGNING_KEY_CURRENT', 'QR_SIGNING_KEY_PREVIOUS', 'IDENTITY_PIN_SEALING_SECRET_KEY',
    'LICENSE_KEY', 'MAIL_PASSWORD', 'BACKUP_ENCRYPTION_KEY', 'BACKUP_DB_PASSWORD',
];

/** Los secretos que reverb no usa: no toca la base de datos, no firma QR ni sella PIN ni envia correo. */
const RUNTIME_ENVIRONMENT_SECRETS_REVERB_DOES_NOT_USE = [
    'DB_PASSWORD', 'QR_SIGNING_KEY_CURRENT', 'QR_SIGNING_KEY_PREVIOUS',
    'IDENTITY_PIN_SEALING_SECRET_KEY', 'LICENSE_KEY', 'MAIL_PASSWORD',
];

/**
 * Claves de `.env.example` que NO llegan a ningun servicio de runtime, y por que.
 *
 * Las de `RUNTIME_ENVIRONMENT_FORBIDDEN` no se repiten aqui: ya tienen motivo.
 * `POSTGRES_*` y `DB_BACKUP_*` no aparecen porque no son claves de
 * `.env.example`: son nombres del contenedor `postgres`, que las recibe
 * interpoladas.
 */
const RUNTIME_ENVIRONMENT_NOT_FORWARDED = [
    'GRAFANA_ADMIN_USER' => 'solo el contenedor grafana',
    'GRAFANA_ADMIN_PASSWORD' => 'solo el contenedor grafana',
    'ALERT_MAINTENANCE_WEEKDAY' => 'solo alertmanager (ventana de silencio); la aplicacion no la lee',
    'ALERT_MAINTENANCE_START' => 'solo alertmanager (ventana de silencio); la aplicacion no la lee',
    'ALERT_MAINTENANCE_END' => 'solo alertmanager (ventana de silencio); la aplicacion no la lee',
    'IMAGE_REGISTRY' => 'la interpola Compose en `image:`; ningun proceso la lee',
    'HTTP_PORT' => 'la interpola Compose en `ports:` de nginx',
    'HTTPS_PORT' => 'la interpola Compose en `ports:` de nginx',
    'TLS_CERT_DIR' => 'la interpola Compose en el volumen del certificado de nginx',
    'BRANDING_PATH' => 'la interpola Compose en el volumen de marca; la aplicacion lee BRANDING_LOGO_ROOT',
    'DB_MAX_SLOT_WAL_KEEP_GB' => 'la interpola Compose en el `command:` de postgres (max_slot_wal_keep_size); ningun proceso de la aplicacion la lee',
    'BACKUP_WAL_KEY' => 'subclave del WAL derivada de BACKUP_ENCRYPTION_KEY (ADR-049): solo la recibe postgres, para su archive_command',
];

const RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO = 'driver o servicio de Laravel que el producto no usa (PostgreSQL, Redis, SMTP y stderr/Loki son los suyos)';

const RUNTIME_ENVIRONMENT_MOTIVO_PDF = 'spatie/laravel-pdf: el producto dibuja con Browsershot y el Chromium de la imagen (LARAVEL_PDF_CHROME_PATH); los demas drivers y su cache no se usan';

const RUNTIME_ENVIRONMENT_MOTIVO_DEFECTO = 'ajuste del framework sin declarar en .env.example: vale el valor del codigo, que es el de produccion';

const RUNTIME_ENVIRONMENT_MOTIVO_PRUEBAS = 'solo pruebas y herramientas de calidad: en produccion no hay repositorio ni contrato montados';

/**
 * Nombres que la configuracion lee con `env()` y que `app` NO recibe a
 * proposito, y por que. Aplica a lo que no esta ya en `.env.example` ni en las
 * listas de arriba.
 */
const RUNTIME_ENVIRONMENT_CODE_DEFAULTS = [
    'APP_VERSION' => 'la fija la imagen (ENV del Dockerfile), no el .env: es la version desplegada',
    'APP_FAKER_LOCALE' => RUNTIME_ENVIRONMENT_MOTIVO_PRUEBAS,
    'REPO_PATH' => RUNTIME_ENVIRONMENT_MOTIVO_PRUEBAS,
    'DOCS_PATH' => RUNTIME_ENVIRONMENT_MOTIVO_PRUEBAS,
    'SPEC_SOURCE' => RUNTIME_ENVIRONMENT_MOTIVO_PRUEBAS,
    'SPEC_PATH' => RUNTIME_ENVIRONMENT_MOTIVO_PRUEBAS,
    'TINKER_TRUST_PROJECT' => RUNTIME_ENVIRONMENT_MOTIVO_PRUEBAS,
    'METRICS_TEXTFILE_PATH' => 'ruta fija dentro de BACKUP_PATH; la comparten los scripts de copia y node-exporter',
    // Bloque 20 (A3-R2): en que contenedor corre product:doctor. Por defecto
    // `app`, que es donde lo lanzan install.sh, update.sh y doctor.sh. Si el
    // compose llega a fijarla por servicio (valor literal), sale de esta lista.
    'KRONOQR_SERVICE' => 'contenedor en el que corre product:doctor; vale app, que es donde se ejecuta siempre',
    'DB_CACHE_CONNECTION' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'DB_CACHE_TABLE' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'DB_CACHE_LOCK_CONNECTION' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'DB_CACHE_LOCK_TABLE' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'DB_QUEUE_CONNECTION' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'DB_QUEUE_TABLE' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'DB_QUEUE' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'DB_QUEUE_RETRY_AFTER' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'DB_FOREIGN_KEYS' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'DB_SOCKET' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'DB_COLLATION' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'DB_ENCRYPT' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'DB_TRUST_SERVER_CERTIFICATE' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'MYSQL_ATTR_SSL_CA' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'MEMCACHED_PERSISTENT_ID' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'MEMCACHED_USERNAME' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'MEMCACHED_PASSWORD' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'MEMCACHED_HOST' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'MEMCACHED_PORT' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'DYNAMODB_CACHE_TABLE' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'DYNAMODB_ENDPOINT' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'AWS_ACCESS_KEY_ID' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'AWS_SECRET_ACCESS_KEY' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'AWS_DEFAULT_REGION' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'AWS_BUCKET' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'AWS_URL' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'AWS_ENDPOINT' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'AWS_USE_PATH_STYLE_ENDPOINT' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'SQS_PREFIX' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'SQS_QUEUE' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'SQS_SUFFIX' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'BEANSTALKD_QUEUE_HOST' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'BEANSTALKD_QUEUE' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'BEANSTALKD_QUEUE_RETRY_AFTER' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'LOG_SLACK_WEBHOOK_URL' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'LOG_SLACK_USERNAME' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'LOG_SLACK_EMOJI' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'LOG_PAPERTRAIL_HANDLER' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'PAPERTRAIL_URL' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'PAPERTRAIL_PORT' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'LOG_SYSLOG_FACILITY' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'POSTMARK_API_KEY' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'POSTMARK_MESSAGE_STREAM_ID' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'RESEND_API_KEY' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'SLACK_BOT_USER_OAUTH_TOKEN' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'SLACK_BOT_USER_DEFAULT_CHANNEL' => RUNTIME_ENVIRONMENT_MOTIVO_DRIVER_SIN_USO,
    'SESSION_COOKIE' => RUNTIME_ENVIRONMENT_MOTIVO_DEFECTO,
    'CONCURRENCY_DRIVER' => RUNTIME_ENVIRONMENT_MOTIVO_DEFECTO,
    'HASH_DRIVER' => RUNTIME_ENVIRONMENT_MOTIVO_DEFECTO,
    'BCRYPT_ROUNDS' => RUNTIME_ENVIRONMENT_MOTIVO_DEFECTO,
    'HASH_VERIFY' => RUNTIME_ENVIRONMENT_MOTIVO_DEFECTO,
    'BCRYPT_LIMIT' => RUNTIME_ENVIRONMENT_MOTIVO_DEFECTO,
    'ARGON_MEMORY' => RUNTIME_ENVIRONMENT_MOTIVO_DEFECTO,
    'ARGON_THREADS' => RUNTIME_ENVIRONMENT_MOTIVO_DEFECTO,
    'ARGON_TIME' => RUNTIME_ENVIRONMENT_MOTIVO_DEFECTO,
    'IMAGE_DRIVER' => RUNTIME_ENVIRONMENT_MOTIVO_DEFECTO,
    'VIEW_COMPILED_PATH' => RUNTIME_ENVIRONMENT_MOTIVO_DEFECTO,
    'HORIZON_PREFIX' => RUNTIME_ENVIRONMENT_MOTIVO_DEFECTO,
    // CH3: tiempos de conexion y lectura con el valor de produccion en el codigo
    // (2 s cada uno). `REDIS_TIMEOUT` ya llega a los servicios y es la de conexion.
    'DB_CONNECT_TIMEOUT' => RUNTIME_ENVIRONMENT_MOTIVO_DEFECTO,
    'REDIS_READ_TIMEOUT' => RUNTIME_ENVIRONMENT_MOTIVO_DEFECTO,
    // R3-CH-01: cuanto recuerda el cortacircuitos que Redis esta caido (10 s en
    // el codigo). Afinarlo es de desarrollo; `0` lo apaga, y lo usa la suite.
    'REDIS_CIRCUIT_BREAKER_SECONDS' => RUNTIME_ENVIRONMENT_MOTIVO_DEFECTO,
    'LARAVEL_PDF_DRIVER' => RUNTIME_ENVIRONMENT_MOTIVO_PDF,
    'LARAVEL_PDF_CACHE_AUTOMATIC' => RUNTIME_ENVIRONMENT_MOTIVO_PDF,
    'LARAVEL_PDF_CACHE_STORE' => RUNTIME_ENVIRONMENT_MOTIVO_PDF,
    'LARAVEL_PDF_CACHE_TTL' => RUNTIME_ENVIRONMENT_MOTIVO_PDF,
    'CLOUDFLARE_API_TOKEN' => RUNTIME_ENVIRONMENT_MOTIVO_PDF,
    'CLOUDFLARE_ACCOUNT_ID' => RUNTIME_ENVIRONMENT_MOTIVO_PDF,
    'GOTENBERG_URL' => RUNTIME_ENVIRONMENT_MOTIVO_PDF,
    'GOTENBERG_USERNAME' => RUNTIME_ENVIRONMENT_MOTIVO_PDF,
    'GOTENBERG_PASSWORD' => RUNTIME_ENVIRONMENT_MOTIVO_PDF,
    'LARAVEL_PDF_DOMPDF_REMOTE_ENABLED' => RUNTIME_ENVIRONMENT_MOTIVO_PDF,
    'LARAVEL_PDF_DOMPDF_CHROOT' => RUNTIME_ENVIRONMENT_MOTIVO_PDF,
    'LARAVEL_PDF_WEASYPRINT_BINARY' => RUNTIME_ENVIRONMENT_MOTIVO_PDF,
    'LARAVEL_PDF_CHROME_BINARY' => RUNTIME_ENVIRONMENT_MOTIVO_PDF,
    'LARAVEL_PDF_CHROME_NO_SANDBOX' => RUNTIME_ENVIRONMENT_MOTIVO_PDF,
    'LARAVEL_PDF_CHROME_STARTUP_TIMEOUT' => RUNTIME_ENVIRONMENT_MOTIVO_PDF,
    'LARAVEL_PDF_CHROME_TIMEOUT' => RUNTIME_ENVIRONMENT_MOTIVO_PDF,
    'LARAVEL_PDF_CHROME_OPERATION_TIMEOUT' => RUNTIME_ENVIRONMENT_MOTIVO_PDF,
    'LARAVEL_PDF_CHROME_USER_DATA_DIR' => RUNTIME_ENVIRONMENT_MOTIVO_PDF,
];

/**
 * Lo que llega al entorno de los servicios de runtime de produccion, unido.
 *
 * @return list<string>
 */
function runtimeEnvironmentDelivered(): array
{
    $services = ComposeEnvironment::services(RUNTIME_ENVIRONMENT_PROD);

    return array_values(array_unique(array_merge(...array_map(
        static fn (string $name): array => ComposeEnvironment::environmentNames(
            $services[$name] ?? throw new RuntimeException(RUNTIME_ENVIRONMENT_PROD." no tiene el servicio «{$name}»."),
        ),
        RUNTIME_ENVIRONMENT_SERVICES,
    ))));
}

/**
 * Lo que un servicio de produccion nombra en cualquier parte de su definicion.
 *
 * @return list<string>
 */
function runtimeEnvironmentReferencedBy(string $service): array
{
    return ComposeEnvironment::referencedNames(ComposeEnvironment::service(RUNTIME_ENVIRONMENT_PROD, $service));
}

// ---------------------------------------------------------------------------
// 1. Sin env_file en el runtime
// ---------------------------------------------------------------------------

it('no inyecta el .env entero en el servicio de runtime', function (string $service): void {
    $definition = ComposeEnvironment::service(RUNTIME_ENVIRONMENT_PROD, $service);

    expect(array_key_exists('env_file', $definition))->toBeFalse(
        "compose.prod.yaml: «{$service}» vuelve a recibir env_file y con el el .env entero, incluida la contraseña "
        .'del migrador (SUPERUSER). ADR-042 §4: el runtime declara su entorno variable a variable en environment:.'
    );
})->with(RUNTIME_ENVIRONMENT_SERVICES)->group('RS-08', 'RS-07', 'RL-04');

it('declara el entorno de cada servicio de runtime', function (string $service): void {
    $environment = ComposeEnvironment::environment(ComposeEnvironment::service(RUNTIME_ENVIRONMENT_PROD, $service));

    expect($environment)->not->toBe(
        [],
        "compose.prod.yaml: «{$service}» no declara environment:. Sin env_file (ADR-042) eso es un contenedor sin "
        .'configuracion, y las comprobaciones de esta prueba pasarian en verde sin mirar nada.'
    );
})->with(RUNTIME_ENVIRONMENT_SERVICES)->group('RS-08');

it('migrate y restore tampoco usan env_file', function (string $service): void {
    $definition = ComposeEnvironment::service(RUNTIME_ENVIRONMENT_PROD, $service);

    expect(array_key_exists('env_file', $definition))->toBeFalse(
        "compose.prod.yaml: «{$service}» recibe env_file. Aunque sea de un solo uso, con el .env entero llevaria "
        .'la clave del QR, la de sellado del PIN y la de copias a un proceso que no las usa (ADR-042 §3).'
    );
})->with(['migrate', 'restore'])->group('RS-08');

// ---------------------------------------------------------------------------
// 2. Confinamiento de credenciales
// ---------------------------------------------------------------------------

it('ningun servicio de runtime nombra una credencial capaz de alterar el registro', function (string $service): void {
    $offenders = array_values(array_intersect(
        array_keys(RUNTIME_ENVIRONMENT_FORBIDDEN),
        runtimeEnvironmentReferencedBy($service),
    ));

    expect($offenders)->toBe(
        [],
        "compose.prod.yaml: «{$service}» nombra ".implode(', ', $offenders).'. ADR-042: ningun proceso del runtime '
        .'posee una credencial que pueda alterar el registro, ni por nombre ni interpolada en otra variable. '
        .'Motivos en RUNTIME_ENVIRONMENT_FORBIDDEN de RuntimeEnvironmentTest.'
    );
})->with(RUNTIME_ENVIRONMENT_SERVICES)->group('RS-08', 'RS-07', 'RL-04');

it('ningun servicio de runtime recibe DB_MIGRATION_* salvo el nombre del rol', function (string $service): void {
    $offenders = array_values(array_filter(
        runtimeEnvironmentReferencedBy($service),
        static fn (string $name): bool => str_starts_with($name, 'DB_MIGRATION_') && $name !== 'DB_MIGRATION_USERNAME',
    ));

    expect($offenders)->toBe(
        [],
        "compose.prod.yaml: «{$service}» recibe ".implode(', ', $offenders).'. Solo migrate y restore reciben '
        .'DB_MIGRATION_* (ADR-042 §3); el runtime, como mucho, el NOMBRE del rol para los GRANT.'
    );
})->with(RUNTIME_ENVIRONMENT_SERVICES)->group('RS-08', 'RS-07', 'RL-04');

it('la credencial de copia y la clave de cifrado no llegan mas que al planificador', function (string $service): void {
    $offenders = array_values(array_intersect(
        RUNTIME_ENVIRONMENT_SCHEDULER_ONLY,
        runtimeEnvironmentReferencedBy($service),
    ));

    expect($offenders)->toBe(
        [],
        "compose.prod.yaml: «{$service}» recibe ".implode(', ', $offenders).'. BACKUP_DB_* y BACKUP_ENCRYPTION_KEY '
        .'solo los necesita el scheduler, que lanza backup:run (ADR-042 §2).'
    );
})->with(['app', 'horizon', 'reverb', 'nginx', 'migrate'])->group('RS-08', 'RL-04');

it('restore no recibe el rol de copia: restaura con el migrador', function (): void {
    $offenders = array_values(array_intersect(
        ['BACKUP_DB_USERNAME', 'BACKUP_DB_PASSWORD'],
        runtimeEnvironmentReferencedBy('restore'),
    ));

    expect($offenders)->toBe(
        [],
        'compose.prod.yaml: restore recibe '.implode(', ', $offenders).'. BACKUP_DB_* solo llega al scheduler (ADR-042).'
    );
})->group('RS-08');

it('la subclave del WAL solo llega a postgres, que no recibe la clave maestra (ADR-049, C7)', function (): void {
    // D2 y C7 del bloque 20. PostgreSQL cifra cada segmento en su
    // `archive_command` con BACKUP_WAL_KEY, una subclave DERIVADA: un postgres
    // comprometido abre el WAL, que ya lee, pero no los volcados ni las copias
    // fisicas. Por eso la maestra no le llega y la derivada no llega a nadie mas;
    // `restore` y el simulacro la reciben por invocacion (`-e BACKUP_WAL_KEY`).
    $services = ComposeEnvironment::services(RUNTIME_ENVIRONMENT_PROD);

    $receivers = array_keys(array_filter(
        $services,
        static fn (array $definition): bool => \in_array('BACKUP_WAL_KEY', ComposeEnvironment::referencedNames($definition), true),
    ));

    expect($receivers)->toBe(
        ['postgres'],
        'compose.prod.yaml: BACKUP_WAL_KEY llega a '.implode(', ', $receivers).'. Solo postgres la necesita (ADR-049): '
        .'en x-runtime-env la tendria todo el runtime sin usarla.'
    );

    expect(\in_array('BACKUP_ENCRYPTION_KEY', runtimeEnvironmentReferencedBy('postgres'), true))->toBeFalse(
        'compose.prod.yaml: postgres recibe BACKUP_ENCRYPTION_KEY. Un postgres comprometido abriria 30 dias de volcados '
        .'y las copias fisicas: recibe solo la subclave del WAL (ADR-049, D2).'
    );
})->group('RL-12', 'RS-08', 'RNF-D-02');

it('la subclave del WAL no tiene valor por defecto ni en el compose ni en .env.example (ADR-049)', function (): void {
    // MEDIO del dictamen: una clave por defecto en produccion es una clave que
    // conoce cualquiera con el paquete, y el WAL «cifrado» con ella esta en claro
    // a efectos de RL-12. La genera install.sh/update.sh en el servidor.
    $environment = ComposeEnvironment::environment(ComposeEnvironment::service(RUNTIME_ENVIRONMENT_PROD, 'postgres'));

    expect(array_key_exists('BACKUP_WAL_KEY', $environment))->toBeTrue(
        'compose.prod.yaml: postgres no recibe BACKUP_WAL_KEY: archive-wal.sh falla cerrado y PostgreSQL retiene el WAL.'
    );

    $value = $environment['BACKUP_WAL_KEY'] ?? null;

    expect($value === null || preg_match('/^\$\{BACKUP_WAL_KEY(?::?\?[^}]*)?\}$/', $value) === 1)->toBeTrue(
        'compose.prod.yaml: postgres.BACKUP_WAL_KEY vale «'.(string) $value.'». Tiene que llegar del .env sin valor por defecto '
        .'(`BACKUP_WAL_KEY:` o `${BACKUP_WAL_KEY:?...}`).'
    );
    expect(Repo::contents(RUNTIME_ENVIRONMENT_PROD))->not->toMatch('/\$\{BACKUP_WAL_KEY:?-/');
    expect(Repo::contents('.env.example'))->toMatch('/^BACKUP_WAL_KEY=[ \t]*(?:#.*)?$/m');
})->group('RL-12', 'RS-08');

it('la aceptacion de copias sin autenticar no vive ni en el compose ni en .env.example (C12)', function (): void {
    // KRONOQR_ACCEPT_UNAUTHENTICATED abre la puerta a restaurar una copia de la
    // 2.1.0 sin MAC. Es una decision por invocacion (`-e` en el `run` de
    // restore), nunca un estado de la instalacion: en el .env o en el compose
    // dejaria de verificarse la integridad de TODAS las restauraciones.
    foreach ([RUNTIME_ENVIRONMENT_PROD, RUNTIME_ENVIRONMENT_DEV] as $compose) {
        expect(str_contains(Repo::contents($compose), 'KRONOQR_ACCEPT_UNAUTHENTICATED'))->toBeFalse(
            $compose.' nombra KRONOQR_ACCEPT_UNAUTHENTICATED: solo se pasa con -e por invocacion (C12).'
        );
    }

    expect(DeclaredEnvironment::keysInEnvExample(Repo::contents('.env.example')))->not->toContain('KRONOQR_ACCEPT_UNAUTHENTICATED');
})->group('RL-12', 'RS-08', 'RL-04');

it('el planificador recibe la credencial de copia de solo lectura y la clave de cifrado', function (): void {
    $names = ComposeEnvironment::environmentNames(ComposeEnvironment::service(RUNTIME_ENVIRONMENT_PROD, 'scheduler'));

    expect(array_values(array_diff(RUNTIME_ENVIRONMENT_SCHEDULER_ONLY, $names)))->toBe(
        [],
        'compose.prod.yaml: al scheduler le falta alguna de '.implode(', ', RUNTIME_ENVIRONMENT_SCHEDULER_ONLY)
        .'. Sin ellas la copia programada no arranca y nadie se entera hasta que hace falta restaurar (RL-12).'
    );
})->group('RS-08', 'RL-04');

it('los nombres de rol del migrador y de mantenimiento no llegan a reverb ni a nginx', function (string $service): void {
    $offenders = array_values(array_intersect(RUNTIME_ENVIRONMENT_ROLE_NAMES, runtimeEnvironmentReferencedBy($service)));

    expect($offenders)->toBe(
        [],
        "compose.prod.yaml: «{$service}» recibe ".implode(', ', $offenders).'. Los nombres de rol solo se admiten en '
        .implode(', ', RUNTIME_ENVIRONMENT_ROLE_NAME_SERVICES).', que escriben o consultan los GRANT.'
    );
})->with(array_values(array_diff(RUNTIME_ENVIRONMENT_SERVICES, RUNTIME_ENVIRONMENT_ROLE_NAME_SERVICES)))->group('RS-08');

it('migrate recibe la credencial del migrador', function (): void {
    $names = ComposeEnvironment::environmentNames(ComposeEnvironment::service(RUNTIME_ENVIRONMENT_PROD, 'migrate'));

    expect(array_values(array_diff(['DB_MIGRATION_USERNAME', 'DB_MIGRATION_PASSWORD'], $names)))->toBe(
        [],
        'compose.prod.yaml: migrate no recibe DB_MIGRATION_USERNAME y DB_MIGRATION_PASSWORD: las migraciones corren '
        .'sobre pgsql_migrator y es el unico sitio donde puede estar esa credencial (ADR-042 §3).'
    );
})->group('RS-08', 'RL-04');

it('migrate no monta ni recibe el destino de las copias', function (): void {
    // `toContain()` de Pest admite varios valores: un mensaje como segundo argumento
    // se buscaría como si fuera otro nombre. La descripción va en la aserción.
    expect(\in_array('BACKUP_PATH', runtimeEnvironmentReferencedBy('migrate'), true))->toBeFalse(
        'compose.prod.yaml: migrate nombra BACKUP_PATH. Migrar no lee ni escribe copias: montarlas da a un proceso '
        .'con credencial de superusuario acceso a todas las copias cifradas sin motivo.'
    );
})->group('RS-08', 'RL-04');

it('restore se conecta como migrador y recibe la clave de cifrado y el destino de las copias', function (): void {
    $restore = ComposeEnvironment::service(RUNTIME_ENVIRONMENT_PROD, 'restore');
    $environment = ComposeEnvironment::environment($restore);

    expect(\in_array('DB_MIGRATION_USERNAME', ComposeEnvironment::interpolatedIn((string) ($environment['PGUSER'] ?? '')), true))->toBeTrue(
        'compose.prod.yaml: restore.PGUSER no sale de DB_MIGRATION_USERNAME. Restaurar exige CREATEDB y renombrar '
        .'bases, que el rol de copia no puede.'
    );
    expect(\in_array('DB_MIGRATION_PASSWORD', ComposeEnvironment::interpolatedIn((string) ($environment['PGPASSWORD'] ?? '')), true))->toBeTrue(
        'compose.prod.yaml: restore.PGPASSWORD no sale de DB_MIGRATION_PASSWORD.'
    );
    expect(array_values(array_diff(['BACKUP_ENCRYPTION_KEY', 'BACKUP_ENCRYPTION_KEY_PREVIOUS', 'BACKUP_PATH'], ComposeEnvironment::environmentNames($restore))))->toBe(
        [],
        'compose.prod.yaml: restore necesita BACKUP_ENCRYPTION_KEY para descifrar (y BACKUP_ENCRYPTION_KEY_PREVIOUS para las copias anteriores a una rotacion) y BACKUP_PATH para encontrar la copia.'
    );
})->group('RS-08', 'RL-04');

it('reverb no monta ningun volumen: ni las copias ni el logotipo de la marca', function (): void {
    $reverb = ComposeEnvironment::service(RUNTIME_ENVIRONMENT_PROD, 'reverb');

    expect($reverb['volumes'] ?? [])->toBe(
        [],
        'compose.prod.yaml: reverb monta volumenes. Solo habla WebSocket con Redis: heredar los de x-app-image le da '
        .'lectura y escritura sobre las copias (BACKUP_PATH). Declara `volumes: []` en el servicio (ADR-042).'
    );
})->group('RS-08', 'RL-04');

/**
 * El origen de un volumen de Compose, en sintaxis larga o corta. En la corta no se
 * parte por los `:` de `${VAR:-valor}`, y de `${VAR:-./certs}` vale el valor por
 * defecto, que es lo que monta Compose si el `.env` no dice otra cosa.
 */
function runtimeEnvironmentMountSource(mixed $volume): string
{
    if (\is_array($volume)) {
        $declared = $volume['source'] ?? '';
        $source = \is_scalar($declared) ? (string) $declared : '';
    } else {
        preg_match('/^(\$\{[^}]*\}|[^:]*)/', \is_scalar($volume) ? (string) $volume : '', $matches);
        $source = $matches[1] ?? '';
    }

    if (preg_match('/^\$\{[A-Za-z_][A-Za-z0-9_]*:-(.*)\}$/', $source, $defaults) === 1) {
        return $defaults[1];
    }

    return $source;
}

/** Un montaje que entrega el `.env` entero sin pasar por `environment:`. */
function runtimeEnvironmentIsForbiddenMount(string $source): bool
{
    $normalised = rtrim($source, '/');

    return \in_array($normalised, ['', '.', '..', '${PWD}', '$PWD'], true)
        || str_starts_with($normalised, '..')
        || basename($normalised) === '.env'
        || str_ends_with($normalised, 'docker.sock');
}

it('ningun servicio de runtime monta el directorio de despliegue, el .env ni el socket de Docker', function (string $service): void {
    // A3-10 (d). Una montura asi entrega el .env entero sin pasar por `environment:`
    // y la comprobacion por nombre de variable no la veria. Es la mitad estatica de
    // `.github/scripts/assert-runtime-env.sh`, que mira los montajes reales con
    // `docker inspect`.
    $definition = ComposeEnvironment::service(RUNTIME_ENVIRONMENT_PROD, $service);
    $volumes = \is_array($definition['volumes'] ?? null) ? $definition['volumes'] : [];
    $sources = array_map(runtimeEnvironmentMountSource(...), $volumes);
    $offenders = array_values(array_filter($sources, runtimeEnvironmentIsForbiddenMount(...)));

    expect($offenders)->toBe(
        [],
        "compose.prod.yaml: «{$service}» monta ".implode(', ', $offenders).'. Un runtime no puede montar el directorio '
        .'de despliegue (contiene el .env), el propio .env ni el socket de Docker: entregaria los secretos sin pasar '
        .'por environment: (A3-10, ADR-042).'
    );
})->with(RUNTIME_ENVIRONMENT_SERVICES)->group('RS-08', 'RS-07', 'RL-04');

it('nginx no recibe ningun secreto de la aplicacion', function (): void {
    $offenders = array_values(array_intersect(RUNTIME_ENVIRONMENT_APP_SECRETS, runtimeEnvironmentReferencedBy('nginx')));

    expect($offenders)->toBe(
        [],
        'compose.prod.yaml: nginx recibe '.implode(', ', $offenders).'. El borde solo usa sus CIDR y el TLS (PIN-10, ADR-042).'
    );
})->group('RS-08');

it('reverb no recibe los secretos que no usa', function (): void {
    $offenders = array_values(array_intersect(
        RUNTIME_ENVIRONMENT_SECRETS_REVERB_DOES_NOT_USE,
        runtimeEnvironmentReferencedBy('reverb'),
    ));

    expect($offenders)->toBe(
        [],
        'compose.prod.yaml: reverb recibe '.implode(', ', $offenders).'. Reverb habla con Redis y sirve WebSocket: '
        .'no toca la base de datos, no firma QR ni sella PIN ni envia correo.'
    );
})->group('RS-08');

// ---------------------------------------------------------------------------
// 3. Completitud: .env.example
// ---------------------------------------------------------------------------

it('cada clave de .env.example llega a un servicio de runtime o esta excluida con su motivo', function (): void {
    $accounted = [
        ...runtimeEnvironmentDelivered(),
        ...array_keys(RUNTIME_ENVIRONMENT_NOT_FORWARDED),
        ...array_keys(RUNTIME_ENVIRONMENT_FORBIDDEN),
        ...RUNTIME_ENVIRONMENT_ROLE_NAMES,
    ];

    $missing = array_values(array_diff(DeclaredEnvironment::keysInEnvExample(Repo::contents('.env.example')), $accounted));

    expect($missing)->toBe(
        [],
        'Estas claves de .env.example no llegan a ningun servicio de runtime de compose.prod.yaml: '
        .implode(', ', $missing).'. Sin env_file, el cliente las pondria en su .env y no surtirian efecto. '
        .'Añadela al servicio que la use o a RUNTIME_ENVIRONMENT_NOT_FORWARDED de RuntimeEnvironmentTest con su motivo '
        .'(ADR-042 §4, ADR-029).'
    );
})->group('RS-08', 'RF-PD-01');

it('la lista de exclusion de .env.example solo nombra claves que existen', function (): void {
    $stale = array_values(array_diff(
        array_keys(RUNTIME_ENVIRONMENT_NOT_FORWARDED),
        DeclaredEnvironment::keysInEnvExample(Repo::contents('.env.example')),
    ));

    expect($stale)->toBe(
        [],
        'RUNTIME_ENVIRONMENT_NOT_FORWARDED nombra claves que ya no estan en .env.example: '.implode(', ', $stale)
        .'. Quitalas: una exclusion sin clave es ruido que tapa las que importan.'
    );
})->group('RS-08');

it('ninguna clave excluida de .env.example llega a la vez a un servicio de runtime', function (): void {
    $contradictions = array_values(array_intersect(array_keys(RUNTIME_ENVIRONMENT_NOT_FORWARDED), runtimeEnvironmentDelivered()));

    expect($contradictions)->toBe(
        [],
        'Estas claves figuran como excluidas y compose.prod.yaml las entrega: '.implode(', ', $contradictions)
        .'. Quitalas de RUNTIME_ENVIRONMENT_NOT_FORWARDED: si mañana dejaran de entregarse, la exclusion lo taparia.'
    );
})->group('RS-08');

// ---------------------------------------------------------------------------
// 4. Completitud: installation_settings
// ---------------------------------------------------------------------------

it('las claves de installation_settings llegan al servicio que compara el entorno con la tabla', function (string $service): void {
    // SettingsDrift y el recolector de diagnostico leen `$_ENV[$key->value]`:
    // el nombre de la variable ES el valor del caso. Una clave que no llegue
    // hace que `doctor` y el paquete de diagnostico digan «sin deriva» sin
    // haber mirado.
    $names = ComposeEnvironment::environmentNames(ComposeEnvironment::service(RUNTIME_ENVIRONMENT_PROD, $service));
    $settings = array_map(static fn (SettingKey $key): string => $key->value, SettingKey::cases());

    $missing = array_values(array_diff($settings, $names));

    expect($missing)->toBe(
        [],
        "compose.prod.yaml: «{$service}» no recibe ".implode(', ', $missing).'. Son claves de SettingKey que '
        .'SettingsDrift y ConfigurationCollector leen de $_ENV. Añadelas al environment: del servicio.'
    );
})->with(['app', 'scheduler'])->group('RS-08', 'RF-PD-01');

// ---------------------------------------------------------------------------
// 5. Completitud: env() de config/
// ---------------------------------------------------------------------------

it('cada env() de la configuracion llega a app o esta excluido con su motivo', function (): void {
    $app = ComposeEnvironment::environmentNames(ComposeEnvironment::service(RUNTIME_ENVIRONMENT_PROD, 'app'));
    $accounted = [
        ...$app,
        ...array_keys(RUNTIME_ENVIRONMENT_CODE_DEFAULTS),
        ...array_keys(RUNTIME_ENVIRONMENT_NOT_FORWARDED),
        ...array_keys(RUNTIME_ENVIRONMENT_FORBIDDEN),
        ...RUNTIME_ENVIRONMENT_SCHEDULER_ONLY,
    ];

    $missing = array_values(array_diff(DeclaredEnvironment::readByConfig(), $accounted));

    expect($missing)->toBe(
        [],
        'La configuracion lee con env() estas variables y compose.prod.yaml no se las entrega a app: '
        .implode(', ', $missing).'. Aunque el cliente las ponga en su .env, la aplicacion usaria el valor por '
        .'defecto sin avisar. Añadela al environment: de app (y a .env.example si es para el cliente) o a '
        .'RUNTIME_ENVIRONMENT_CODE_DEFAULTS de RuntimeEnvironmentTest con su motivo.'
    );
})->group('RS-08', 'RF-PD-01');

it('recorre la configuracion de verdad', function (): void {
    // Suelo, no medida: detecta que el recorrido se ha roto (bind mount, ruta),
    // que dejaria la prueba anterior en verde sin haber leido nada.
    expect(count(DeclaredEnvironment::configFiles()))->toBeGreaterThan(25);
    expect(count(DeclaredEnvironment::readByConfig()))->toBeGreaterThan(200);
})->group('RS-08');

it('la lista de valores por defecto del codigo solo nombra variables que la configuracion lee', function (): void {
    $stale = array_values(array_diff(array_keys(RUNTIME_ENVIRONMENT_CODE_DEFAULTS), DeclaredEnvironment::readByConfig()));

    expect($stale)->toBe(
        [],
        'RUNTIME_ENVIRONMENT_CODE_DEFAULTS nombra variables que ya no lee ninguna configuracion: '.implode(', ', $stale).'. Quitalas.'
    );
})->group('RS-08');

it('ninguna variable con valor por defecto del codigo llega a la vez a app', function (): void {
    $app = ComposeEnvironment::environmentNames(ComposeEnvironment::service(RUNTIME_ENVIRONMENT_PROD, 'app'));
    $contradictions = array_values(array_intersect(array_keys(RUNTIME_ENVIRONMENT_CODE_DEFAULTS), $app));

    expect($contradictions)->toBe(
        [],
        'Estas variables figuran en RUNTIME_ENVIRONMENT_CODE_DEFAULTS y compose.prod.yaml se las entrega a app: '
        .implode(', ', $contradictions).'. Quitalas de la lista: si dejaran de entregarse, la exclusion lo taparia.'
    );
})->group('RS-08');

// ---------------------------------------------------------------------------
// 6. La excepcion de desarrollo
// ---------------------------------------------------------------------------

it('compose.dev.yaml conserva env_file en el servicio', function (string $service): void {
    $definition = ComposeEnvironment::service(RUNTIME_ENVIRONMENT_DEV, $service);

    expect(array_key_exists('env_file', $definition))->toBeTrue(
        "compose.dev.yaml: «{$service}» ya no recibe env_file. Es una EXCEPCION DOCUMENTADA en ADR-042: la suite usa "
        .'pgsql_migrator para migrate:fresh y para simular la manipulacion por fuera (AuditLogTest, RetentionTest). '
        .'En desarrollo no hay registro legal ni datos reales. Lee el ADR antes de «arreglarlo».'
    );
})->with(RUNTIME_ENVIRONMENT_SERVICES)->group('RS-08');

// ---------------------------------------------------------------------------
// Los lectores, contra casos escritos a mano
// ---------------------------------------------------------------------------

it('lee de .env.example las claves activas y la configuracion comentada, no la prosa', function (string $line, array $expected): void {
    expect(DeclaredEnvironment::keysInEnvExample($line))->toBe($expected);
})->with([
    'activa' => ['APP_NAME=KronoQR', ['APP_NAME']],
    'activa vacia' => ['APP_KEY=', ['APP_KEY']],
    'activa con nota' => ['DB_USERNAME=fichaje_app                # Sin DDL', ['DB_USERNAME']],
    'comentada' => ['# REVERB_ALLOWED_ORIGINS=panel.hotel.example', ['REVERB_ALLOWED_ORIGINS']],
    'comentada sin espacio' => ['#IDENTITY_PIN_FORBIDDEN=000000,111111,123456', ['IDENTITY_PIN_FORBIDDEN']],
    'comentada con nota' => ['# REALTIME_PATH=/app                          # Ruta del WebSocket', ['REALTIME_PATH']],
    'comentada vacia con nota' => ['# DB_MAINTENANCE_PASSWORD=            # Vacia a proposito.', ['DB_MAINTENANCE_PASSWORD']],
    'prosa de variable retirada' => ['# PORTAL_INTERNAL_ONLY=true sin que nada la leyera; se retiro para no dejar un', []],
    'prosa de cabecera' => ['# APP_ENV=local. Los valores marcados "SOLO DESARROLLO" son placeholders sin', []],
    'titulo' => ['# Redis 7 - colas, cache, rate limiting, sesiones', []],
])->group('RS-08');

it('reconoce las variables que Compose interpola y no el dolar escapado', function (string $text, array $expected): void {
    expect(ComposeEnvironment::interpolatedIn($text))->toBe($expected);
})->with([
    'con defecto' => ['${DB_MIGRATION_USERNAME:-fichaje_migrator}', ['DB_MIGRATION_USERNAME']],
    'obligatoria' => ['${DB_MIGRATION_PASSWORD:?define DB_MIGRATION_PASSWORD en el .env}', ['DB_MIGRATION_PASSWORD']],
    'sin llaves' => ['$BACKUP_PATH/wal', ['BACKUP_PATH']],
    'dos en un valor' => ['-c lock_timeout=${DB_LOCK_TIMEOUT:-5s} -c x=${DB_IDLE_IN_TRANSACTION_TIMEOUT:-60s}', ['DB_LOCK_TIMEOUT', 'DB_IDLE_IN_TRANSACTION_TIMEOUT']],
    'dolar escapado' => ['pg_isready -U "$${POSTGRES_USER}"', []],
    'literal' => ['fichaje', []],
])->group('RS-08');

it('reconoce las lecturas env() de un fichero de configuracion', function (string $source, array $expected): void {
    expect(DeclaredEnvironment::readBySource($source))->toBe($expected);
})->with([
    'comillas simples' => ["'key' => env('APP_KEY'),", ['APP_KEY']],
    'con defecto' => ["env('DB_PORT', '5432')", ['DB_PORT']],
    'comillas dobles y espacios' => ['env( "REDIS_HOST" )', ['REDIS_HOST']],
    'nombre en minusculas' => ["env('app_key')", []],
    'otra funcion' => ["getenv('APP_KEY')", []],
])->group('RS-08');

it('funde las anclas del entorno como Compose y deja ganar a las claves propias', function (): void {
    // Si symfony/yaml no resolviera `<<`, `app` saldria con solo PGOPTIONS y
    // las pruebas de confinamiento pasarian en verde sin haber visto el ancla.
    $names = ComposeEnvironment::environmentNames(ComposeEnvironment::service(RUNTIME_ENVIRONMENT_PROD, 'app'));

    expect($names)->toContain('APP_KEY')->toContain('PGOPTIONS');
})->group('RS-08');
