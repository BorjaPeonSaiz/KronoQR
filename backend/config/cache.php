<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache store that will be used by the
    | framework. This connection is utilized if another isn't explicitly
    | specified when running a cache operation inside the application.
    |
    */

    /*
     * LA CACHE POR DEFECTO SOBREVIVE A UNA CAIDA DE REDIS (CH1, regla dura 19).
     *
     * `CACHE_STORE` sigue eligiendo el almacen de verdad —Redis en toda
     * instalacion real—, pero la aplicacion no lo usa directamente: usa
     * `resilient`, que es ese mismo almacen con el disco local detras. Si Redis
     * no responde, cada lectura y cada escritura caen al almacen `file` en vez de
     * lanzar la `RedisException`.
     *
     * Por que hace falta: la cache esta en el camino de fichaje sin que se vea.
     * La configuracion operativa se lee a traves de ella en cada escaneo, y el
     * contador de fallos del PIN (RS-12) vive en ella. Con el almacen directo, la
     * verificacion de la 2.1.0 obtuvo un `500` por cada fichaje con Redis parado.
     *
     * Por que el disco y no la memoria: una cache `array` dura una peticion, y el
     * contador de fallos del PIN y el de los accesos al panel dejarian de contar
     * durante la averia. En el disco siguen contando —en esta maquina, que es la
     * unica que atiende peticiones (ADR-017, una instalacion por cliente)—.
     * Cuando Redis vuelve, se vuelve a leer de el: lo escrito en el disco durante
     * la averia caduca solo, y como mucho un contador empieza de cero.
     */
    'default' => 'resilient',

    /*
     * EL LIMITADOR NO CAE AL DISCO: usa el almacen de verdad, sin red.
     *
     * Con Redis caido, `throttle` lanza, y eso es lo que se quiere en `auth`,
     * `portal`, `setup` y gestion: fallar CERRADO donde el limitador frena la
     * fuerza bruta de una credencial. Las tres rutas de fichaje lo capturan y
     * fallan ABIERTO con `ThrottleScanFailOpen`, que es la unica excepcion.
     */
    'limiter' => env('CACHE_STORE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    | Supported drivers: "array", "database", "file", "memcached",
    |                    "redis", "dynamodb", "octane",
    |                    "failover", "null"
    |
    */

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        'memcached' => [
            'driver' => 'memcached',
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'sasl' => [
                env('MEMCACHED_USERNAME'),
                env('MEMCACHED_PASSWORD'),
            ],
            'options' => [
                // Memcached::OPT_CONNECT_TIMEOUT => 2000,
            ],
            'servers' => [
                [
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

        'dynamodb' => [
            'driver' => 'dynamodb',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'table' => env('DYNAMODB_CACHE_TABLE', 'cache'),
            'endpoint' => env('DYNAMODB_ENDPOINT'),
        ],

        'octane' => [
            'driver' => 'octane',
        ],

        'failover' => [
            'driver' => 'failover',
            'stores' => [
                'database',
                'array',
            ],
        ],

        // La cache por defecto: el almacen de `CACHE_STORE` con el disco local
        // detras. Ver el comentario de `default`.
        'resilient' => [
            'driver' => 'failover',
            'stores' => [
                env('CACHE_STORE', 'database'),
                'file',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing the APC, database, memcached, Redis, and DynamoDB cache
    | stores, there might be other applications using the same cache. For
    | that reason, you may prefix every cache key to avoid collisions.
    |
    */

    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-cache-'),

];
