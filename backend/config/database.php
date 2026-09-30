<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Pdo\Mysql;

/*
 * CUANTO SE ESPERA A QUE POSTGRESQL CONTESTE AL CONECTAR (CH3).
 *
 * `pdo_pgsql` traduce `ATTR_TIMEOUT` al `connect_timeout` de libpq y, sin el,
 * espera 30 s por intento. Con la base de datos inalcanzable, `GET
 * /api/v1/ready` tardaba 15,6 s en dar su `503`, y un orquestador con una sonda
 * de 1-5 s habria visto «la sonda no responde» en vez de «la base de datos esta
 * caida». PostgreSQL vive en la misma red de Docker: si no contesta en 2 s, no
 * va a contestar. Solo afecta a la CONEXION; una consulta larga la acota
 * `statement_timeout`, no esto. Vale para las cuatro conexiones de PostgreSQL.
 */
$pgsqlOptions = [
    PDO::ATTR_TIMEOUT => (int) env('DB_CONNECT_TIMEOUT', 2),
];

/*
 * CUANTO SE ESPERA A REDIS (CH1, CH3).
 *
 * Sin limite, `phpredis` hereda `default_socket_timeout` (60 s): con Redis
 * inalcanzable por la red —no apagado, que rechaza al instante—, cada acceso a
 * la cache, al limitador o a las metricas colgaba la peticion un minuto, y el
 * fichaje, que ya no depende de Redis (CH1), habria esperado igual.
 *
 * `REDIS_TIMEOUT` es la variable que ya usa el escalado de Reverb para lo mismo
 * —el tiempo de conexion— y la que Compose ya entrega a los servicios; aqui su
 * valor por defecto es 1 s porque Redis esta en la misma red de Docker. La de
 * lectura acota un comando que no contesta; 2 s sobran para todo lo que hace el
 * producto, que no usa lecturas bloqueantes (`block_for` es nulo en la cola).
 */
$redisTimeouts = [
    'timeout' => (float) env('REDIS_TIMEOUT', 1.0),
    'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 2.0),
];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        /*
         * Conexion de RUNTIME. Corre con `fichaje_app`: sin DDL, y sobre
         * `audit_log` solo `INSERT` y `SELECT` (regla dura 6, tarea 1.14).
         */
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'options' => $pgsqlOptions,
        ],

        /*
         * Conexion de MIGRACION. Misma base, otro rol.
         *
         * Existe porque la garantia de la regla dura 6 depende de que el rol de
         * la aplicacion **no** sea propietario ni superusuario: sobre un
         * superusuario, PostgreSQL ni siquiera comprueba los GRANT, y un
         * propietario puede volver a otorgarse lo que se le revoque. Con un
         * solo rol para todo, "sin UPDATE ni DELETE sobre audit_log" era una
         * frase; con dos, es una comprobacion del motor.
         *
         * Consecuencia practica: las migraciones se lanzan indicando la
         * conexion, y por eso `make seed` y el `migrateFreshUsing()` de la
         * suite lo hacen explicitamente:
         *
         *   php artisan migrate --database=pgsql_migrator --force
         *
         * NADA de la aplicacion en marcha usa esta conexion (ADR-042): solo
         * las migraciones y las pruebas. La particion anual de `audit_log`,
         * que era su ultimo uso en runtime, la crea desde la 2.2.0 la funcion
         * `audit_log_create_partition` invocada con el rol de la aplicacion.
         * Si algun dia codigo de `app/` la nombrara, seria un defecto, y lo
         * detectan dos pruebas: `RuntimeDatabaseCredentialsTest` (ningun
         * fichero de `app/` la nombra) y la de integracion de RS-07 (el rol de
         * runtime sigue chocando con el REVOKE). En produccion, ademas, su
         * credencial solo llega a los servicios `migrate` y `restore`.
         */
        'pgsql_migrator' => [
            'driver' => 'pgsql',
            'url' => env('DB_MIGRATION_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_MIGRATION_USERNAME', 'fichaje_migrator'),
            'password' => env('DB_MIGRATION_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'options' => $pgsqlOptions,
        ],

        /*
         * Conexion de MANTENIMIENTO (ADR-027, ADR-033, tarea 2.10). Misma base,
         * tercer rol.
         *
         * La usa **una sola cosa**: soltar una particion vencida de `audit_log`,
         * despues de verificar su cadena y sellar su ancla. Ni la aplicacion en
         * marcha ni las migraciones la resuelven nunca.
         *
         * SU CONTRASENA NO VIVE EN EL `.env` DE LA APLICACION, y esa es la mitad
         * de la garantia: si la instalacion corriente pudiera autenticarse con
         * este rol, el reparto de ADR-033 seria decorativo. Se aporta en el
         * momento de ejecutar la purga, que es una operacion manual y anual:
         *
         *   docker compose run --rm -e DB_MAINTENANCE_PASSWORD=... app \
         *     php artisan compliance:apply-retention --confirm=PURGAR-…
         *
         * Sin ella, `--dry-run` sigue funcionando -solo cuenta, y cuenta con el
         * rol de la aplicacion- y la ejecucion real falla diciendo que falta.
         */
        'pgsql_maintenance' => [
            'driver' => 'pgsql',
            'url' => env('DB_MAINTENANCE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_MAINTENANCE_USERNAME', 'fichaje_maintenance'),
            'password' => env('DB_MAINTENANCE_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'options' => $pgsqlOptions,
        ],

        /*
         * Conexion del HISTORICO DE ERRORES (RF-PD-15, tarea 5.12, decision 6).
         * Misma base, mismo rol, mismas variables de entorno: es una copia
         * literal de `pgsql` y NO introduce ninguna variable nueva.
         *
         * ENTONCES, ¿PARA QUE EXISTE? Para que la escritura del error no viaje
         * dentro de la transaccion que acaba de fallar.
         *
         * El caso concreto: un caso de uso abre una transaccion, algo revienta a
         * mitad y el enganche del manejador de excepciones intenta guardar el
         * fallo. Sobre la conexion por defecto hay dos desenlaces, los dos malos.
         * Si la transaccion sigue abierta, el `INSERT` entra dentro y **se
         * revierte con ella**: el unico rastro del error desaparece justo por ser
         * un error. Si PostgreSQL ya la aborto —`25P02`, «current transaction is
         * aborted»—, cualquier sentencia posterior sobre esa sesion falla, asi
         * que el intento de registrar el error produce un segundo error (regla
         * dura 19: un error al guardar el error no puede convertirse en otro).
         *
         * Con una conexion propia hay **otra sesion de PostgreSQL**, con su
         * propio estado transaccional: el `INSERT … ON CONFLICT` confirma solo y
         * sobrevive al `ROLLBACK` de quien fallo. Es la misma tecnica que usan
         * los manejadores de errores que escriben en base de datos en cualquier
         * stack, y el motivo por el que el driver no la puede resolver solo.
         *
         * MISMO ROL Y NO UNO NUEVO, al contrario que `pgsql_migrator` y
         * `pgsql_maintenance`: aqui no se separa un privilegio, se separa una
         * SESION. El rol de la aplicacion necesita exactamente lo que ya tiene
         * sobre esta tabla —`INSERT`, `UPDATE` para el recuento y `DELETE` para
         * la purga—, y pedirle al cliente una credencial mas para esto seria una
         * variable de entorno mas que documentar, probar y soportar sin ninguna
         * garantia nueva a cambio.
         *
         * CONSECUENCIA PRACTICA EN LAS PRUEBAS: una prueba que abra una
         * transaccion y consulte `error_events` por esta conexion vera lo
         * confirmado por la otra sesion, no lo suyo. Es la propiedad que se
         * quiere y por la que las pruebas de escritura usan `CommittedDatabase`.
         */
        'error_events' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'options' => $pgsqlOptions,
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,

        /*
         * La conexion con la que se ejecutan las migraciones. Laravel no lee
         * esta clave —solo mira `--database`—, pero la lee KronoQR: la usan el
         * `migrateFreshUsing()` de la suite y `Tests\Support\Database\
         * TestDatabase`, para que la conexion correcta este declarada en un
         * sitio y no repetida en cada invocacion. Solo la leen las pruebas y
         * quien lanza las migraciones; ningun codigo de `app/` (ADR-042).
         */
        'connection' => env('DB_MIGRATION_CONNECTION', 'pgsql_migrator'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Roles de base de datos (regla dura 6, ADR-027)
    |--------------------------------------------------------------------------
    |
    | Tres roles con tres trabajos. Las migraciones necesitan los nombres para
    | escribir los `GRANT` y los `REVOKE`, y la prueba de integracion de RS-07
    | los necesita para comprobar que siguen puestos.
    |
    | `maintenance` es el unico que podra soltar una particion de `audit_log`
    | (tarea 2.10). Aqui aparece su NOMBRE, nunca su contraseña: no es un rol de
    | la aplicacion y su credencial no vive en el `.env` de la aplicacion.
    |
    */

    'roles' => [
        'application' => env('DB_USERNAME', 'fichaje_app'),
        'migration' => env('DB_MIGRATION_USERNAME', 'fichaje_migrator'),
        'maintenance' => env('DB_MAINTENANCE_USERNAME', 'fichaje_maintenance'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
            ...$redisTimeouts,
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
            ...$redisTimeouts,
        ],

    ],

];
