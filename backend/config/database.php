<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Pdo\Mysql;

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
         * NADA de la aplicacion en marcha usa esta conexion. Si algun dia un
         * caso de uso la resolviera, seria un defecto: la prueba de integracion
         * de RS-07 comprueba que el rol de runtime sigue chocando con el
         * REVOKE.
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
         * sitio y no repetida en cada invocacion.
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
        ],

    ],

];
