<?php

declare(strict_types=1);

use App\Modules\Product\Infrastructure\Logging\RedactPersonalData;
use App\Support\Observability\Logging\AddCorrelation;
use App\Support\Observability\Logging\ConsoleAwareStreamHandler;
use App\Support\Observability\Logging\ContainerLogChannel;
use App\Support\Observability\Logging\LogChannelStack;
use App\Support\Observability\Logging\LokiLogChannel;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

/*
 * La pila de canales activos (doc 02 §8.2.1, decision 8 de la ficha 3.1).
 *
 * `stderr` es el canal PRIMARIO y no es negociable: Docker lo conserva (`docker compose logs`)
 * y sigue existiendo cuando Loki no esta; el paquete de diagnostico NO lo lee. `loki`
 * es la COPIA CONSULTABLE, y entra en la pila **solo si `LOKI_URL` tiene
 * valor**: en una instalacion que no lo use, el canal no se construye, su
 * handler no se resuelve y no hay ni un viaje a la red. Y al reves: con
 * `LOKI_URL` vacia el canal NO entra aunque `LOG_STACK` lo nombre.
 *
 * Se calcula y no se declara con una lista en el `.env` para que no haya dos
 * sitios donde decir lo mismo: quien pone `LOKI_URL` ya ha dicho que quiere Loki.
 *
 * El calculo vive en {@see LogChannelStack} y no en estas lineas porque desde
 * aqui no se puede probar —la suite `Unit` no arranca el framework y este
 * fichero llama a `storage_path()`—, y una semantica que sorprende sin prueba
 * que la fije cambia sola en el proximo `.env` que alguien edite.
 */
$stack = LogChannelStack::compose(
    (string) env('LOG_STACK', LogChannelStack::PRIMARY),
    (string) env('LOKI_URL', ''),
);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    | `stack` Y NO `stderr` (decision 8 de la ficha 3.1). Los dos escriben lo
    | mismo por `stderr`; la diferencia es que la pila puede llevar ademas el
    | canal `loki` cuando la instalacion lo tenga configurado. Con
    | `LOG_CHANNEL=stderr` en el `.env`, `LOKI_URL` no se usa aunque este puesta:
    | es el estado del que venia el repositorio y parte del motivo de que nadie
    | leyera esa variable.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => $stack,
            /*
             * `false` a proposito, y con matiz: la pila deja que una excepcion de
             * un canal se propague. El unico canal que podria lanzar es `loki`, y
             * no lanza —{@see \App\Support\Observability\Logging\LokiHandler} se
             * traga todo—, asi que envolverlo en `WhatFailureGroupHandler`
             * ocultaria un fallo de `stderr`, que si importa.
             */
            'ignore_exceptions' => false,
        ],

        /*
         * `RedactPersonalData` va en el `tap` de TODOS los canales que escriben
         * a algun sitio (L1, regla dura 21): aplica el saneador de
         * `error_events` al mensaje, al contexto y a la excepcion de cada
         * linea, el ultimo de la cadena. La pila no lo declara porque hereda
         * los processors de sus canales. `EveryLogChannelRedactsPersonalDataTest`
         * falla si un canal llega sin el.
         */
        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
            'tap' => [AddCorrelation::class, RedactPersonalData::class],
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
            'tap' => [AddCorrelation::class, RedactPersonalData::class],
        ],

        /*
         * Lo declara el propio framework y Laravel fusiona sus canales por
         * defecto con los de aqui: si no se redeclarara, existiria igual pero
         * SIN el saneado. Lo descubrio `EveryLogChannelRedactsPersonalDataTest`.
         */
        'monthly' => [
            'driver' => 'monthly',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'max_files' => 3,
            'replace_placeholders' => true,
            'tap' => [AddCorrelation::class, RedactPersonalData::class],
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
            'tap' => [RedactPersonalData::class],
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
            'tap' => [RedactPersonalData::class],
        ],

        /*
         * EL CANAL PRIMARIO DEL PRODUCTO (doc 02 §8.2.1).
         *
         * El formateador JSON se declara AQUI, versionado, y no en
         * `LOG_STDERR_FORMATTER`. El formato del log tecnico es una propiedad del
         * producto —lo consultan Loki, `docker compose logs` y quien depura una
         * incidencia—, y dejarlo en una variable de entorno significaba que
         * un `.env` mal copiado convertia todo el historico en texto suelto sin
         * que nada fallara. La variable sigue pudiendo existir en un `.env`
         * antiguo: ya no la lee nadie (decision 9 de la ficha 3.1).
         *
         * En una orden de consola lanzada con `docker compose exec` (no el PID 1 del
         * contenedor), el registro va al log del contenedor y no a la terminal del
         * operador: {@see ConsoleAwareStreamHandler} (V1-PL-01, V21-4-PL-2).
         */
        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => ConsoleAwareStreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => JsonFormatter::class,
            'processors' => [PsrLogMessageProcessor::class],
            'tap' => [AddCorrelation::class, RedactPersonalData::class],
        ],

        /*
         * LAS TAREAS PROGRAMADAS EN SEGUNDO PLANO (V4-BE-1, RNF-D-02, regla
         * dura 21). Lo usa `App\Support\Scheduling\ScheduledTaskOutput`, desde
         * `schedule:finish`, para dejar en el log del contenedor `scheduler` la
         * salida de cada tarea y su codigo de salida.
         *
         * La pila del producto con `stderr` cambiado por `scheduler-container`
         * ({@see LogChannelStack::forScheduler()}): `schedule:finish` corre con
         * su error tirado a `/dev/null` y alli `stderr` no llega a nadie.
         *
         * `ignore_exceptions` A `true`, al reves que en `stack`: un fallo al
         * escribir esta linea no puede impedir que corran los demas callbacks de
         * la tarea —el `onFailure()` de `scheduler.command_failed` va detras— ni
         * que se libere su candado. El tap de saneado se declara aunque la pila
         * herede el de cada canal: deja uno solo y el ultimo.
         */
        'scheduler' => [
            'driver' => 'stack',
            'channels' => LogChannelStack::forScheduler($stack),
            'ignore_exceptions' => true,
            'tap' => [RedactPersonalData::class],
        ],

        /*
         * El log del contenedor visto desde un proceso cuya salida no llega a
         * el: `/proc/1/fd/2`, la salida de error de `schedule:work`. Si no se
         * puede abrir, escribe por la salida de error propia. Ver
         * {@see ContainerLogChannel}.
         */
        LogChannelStack::SCHEDULER_PRIMARY => [
            'driver' => 'custom',
            'via' => ContainerLogChannel::class,
            'stream' => '/proc/1/fd/2',
            'level' => env('LOG_LEVEL', 'debug'),
            'tap' => [AddCorrelation::class, RedactPersonalData::class],
        ],

        /*
         * LA COPIA CONSULTABLE (decision 8 de la ficha 3.1). Se empuja desde el
         * proceso, sin agente de recoleccion; ver
         * {@see \App\Support\Observability\Logging\LokiHandler} para el porque y
         * para las garantias: un envio, un segundo, sin reintentos, y cualquier
         * fallo se traga.
         *
         * Solo entra en la pila si `LOKI_URL` tiene valor. Ver el calculo de
         * `$stack` al principio del fichero.
         */
        'loki' => [
            'driver' => 'custom',
            'via' => LokiLogChannel::class,
            'url' => env('LOKI_URL', ''),
            /*
             * Etiqueta `service` de Loki. Se comparte con `service.name` de las
             * trazas a proposito: es lo que permite saltar de un log a su traza en
             * Grafana sin traducir nombres por el camino.
             */
            'service' => env('OTEL_SERVICE_NAME', 'kronoqr-api'),
            'environment' => env('APP_ENV', 'production'),
            'level' => env('LOG_LEVEL', 'debug'),
            'timeout' => 1.0,
            /*
             * Techo del buffer en memoria. Un comando que escribe cien mil lineas
             * no puede quedarse sin memoria por culpa del canal de log; a partir
             * de aqui se descartan y se cuentan.
             */
            'buffer_size' => 1000,
            'tap' => [AddCorrelation::class, RedactPersonalData::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
            'tap' => [RedactPersonalData::class],
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
            'tap' => [RedactPersonalData::class],
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        /*
         * No es un canal: es la ruta del logger que Laravel monta A MANO cuando
         * el canal configurado no se puede construir, y ese montaje no lee ni
         * `tap` ni `processors`. Por eso aqui no se declara `RedactPersonalData`
         * (no tendria efecto): el saneado lo anade en el codigo
         * `App\Modules\Product\Infrastructure\Logging\RedactingLogManager`
         * (ADR-048, regla dura 21), y `EmergencyChannelRedactsTest` lo fija.
         */
        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
