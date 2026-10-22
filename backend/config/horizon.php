<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
 * Horizon (2.3.0, bloque 14).
 *
 * Hasta la 2.3.0 el producto no publicaba este fichero y Horizon usaba el suyo
 * de serie: un supervisor sobre la cola `default`. Se publica para declarar el
 * **segundo supervisor**, el de los informes en diferido, sobre la conexion
 * `reports` de `config/queue.php`. Todo lo demas es el valor de serie de
 * Horizon, sin cambios.
 *
 * ## Por que un supervisor aparte para los informes
 *
 * - **Su `retry_after` es otro**: un informe puede tardar hasta
 *   `GenerateReportExportJob::TIMEOUT_SECONDS` (30 min), y con los 90 s de la
 *   conexion normal otro proceso lo volvia a recoger a mitad. El `timeout` de
 *   este supervisor va entre los dos: por encima del techo del trabajo y por
 *   debajo de `retry_after` (1900 s), como pide Horizon.
 * - **Su concurrencia es otra**: dos procesos como mucho. Un informe largo
 *   —la exportacion para la Inspeccion de cuatro años, el informe de un año—
 *   cruza mucho registro contra la misma base de datos que atiende el fichaje
 *   (RNF-P-02, regla dura 19); dos a la vez es el techo, y el resto espera en
 *   cola. Los oyentes del fichaje (presencia, minutos) siguen en `default` y no
 *   esperan detras de ningun informe.
 */
return [
    'name' => env('HORIZON_NAME'),

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    'middleware' => ['web'],

    'waits' => [
        'redis:default' => 60,
        // Un informe que espera en cola varios minutos detras de otro no es una
        // alarma: es el tope de dos procesos haciendo su trabajo.
        'reports:reports' => 1800,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'silenced_tags' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
        'supervisor-reports' => [
            'connection' => 'reports',
            'queue' => ['reports'],
            'balance' => 'simple',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            // El XLSX y el PDF de un informe largo se componen en memoria.
            'memory' => 256,
            'tries' => 1,
            // Entre `GenerateReportExportJob::TIMEOUT_SECONDS` (1800) y el
            // `retry_after` de la conexion `reports` (1900).
            'timeout' => 1850,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],
        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
            ],
        ],
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
