<?php

declare(strict_types=1);

/*
 * El servidor WebSocket de la presencia en vivo (ADR-011, doc 02 §3.4).
 *
 * RECORTADO A LO QUE ESTA INSTALACION USA. Del fichero que publica el paquete se
 * ha quitado la ingesta de Telescope —no esta en el stack del §3.1— y se ha
 * dejado el escalado apagado: ADR-016 dice que cada cliente tiene UNA
 * instalacion, asi que no hay un segundo proceso de Reverb con el que compartir
 * mensajes por Redis. Encenderlo sin necesitarlo solo añade una dependencia mas
 * que puede caerse. La de Pulse **no se puede quitar** aunque Pulse tampoco este:
 * ver el comentario de `pulse_ingest_interval`.
 *
 * EL PROCESO. Corre en su propio contenedor (`infra/compose.*.yaml`, servicio
 * `reverb`) con `reverb:start --host=0.0.0.0 --port=8080`. Nginx le proxifica
 * `/app/` —el WebSocket del navegador— y `/apps/` —la API HTTP con la que el
 * servidor publica mensajes y consulta metricas—, con la cabecera de *upgrade*
 * puesta. La CSP ya permite `connect-src 'self' wss:` (§7.2).
 *
 * LOS CREDENCIALES SE GENERAN EN LA INSTALACION. El repositorio no lleva ninguno:
 * `REVERB_APP_SECRET` no tiene valor por defecto ni aqui ni en `.env.example`, y
 * sin el Reverb no arranca, que es el fallo ruidoso que se prefiere a uno
 * silencioso con una clave conocida.
 */

return [

    'default' => env('REVERB_SERVER', 'reverb'),

    'servers' => [

        'reverb' => [
            /*
             * Escucha en todas las interfaces del contenedor y no publica puerto
             * al exterior: quien llega lo hace por Nginx (§7.2). El aislamiento
             * lo da la red de Docker, no este valor.
             */
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                // Sin TLS en el proceso: lo termina Nginx, que es quien tiene el
                // certificado de la instalacion. Un segundo sitio donde renovar
                // certificados es un sitio mas donde caducan.
                'tls' => [],
            ],

            /*
             * Techo del cuerpo de una peticion a la API HTTP de Reverb. El
             * mensaje de presencia son unos cientos de bytes; 10 kB deja margen
             * de sobra y acota lo que un cliente puede empujar.
             */
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10_000),

            /*
             * APAGADO, y es una decision: ADR-016 —una instalacion por cliente—
             * significa que no hay un segundo proceso de Reverb con el que
             * repartirse las conexiones. El escalado por Redis solo tiene sentido
             * con varios, y encendido añade una dependencia mas en el camino de
             * un mensaje que ya es accesorio.
             */
            'scaling' => [
                'enabled' => env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'server' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', 'redis'),
                    'port' => env('REDIS_PORT', '6379'),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', '0'),
                    'timeout' => env('REDIS_TIMEOUT', 60),
                ],
            ],

            /*
             * NO SE PUEDE QUITAR aunque ni Pulse ni Telescope esten en el stack
             * del §3.1: `reverb:start` lee `pulse_ingest_interval` **sin valor
             * por defecto** y el proceso muere al arrancar con «Undefined array
             * key». Comprobado quitandolo. Se deja con el valor de serie y con
             * este comentario, para que el siguiente que recorte este fichero no
             * repita el intento.
             */
            'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),
        ],

    ],

    /*
     * Una sola aplicacion. No hay multi-tenencia (CLAUDE.md): cada cliente tiene
     * su instalacion entera, asi que una lista de aplicaciones seria una lista
     * de un elemento con sitio para equivocarse.
     */
    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST', 'reverb'),
                    'port' => (int) env('REVERB_PORT', 8080),
                    'scheme' => env('REVERB_SCHEME', 'http'),
                    'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
                ],

                /*
                 * El panel se sirve del MISMO origen que la API (ADR-017: sin
                 * CORS), asi que el origen del WebSocket es el del propio hotel y
                 * ni el fabricante ni esta configuracion lo conocen. Se deja
                 * abierto a proposito y **la defensa no esta aqui**: esta en que
                 * todos los canales son privados y su suscripcion la firma el
                 * servidor con el alcance de quien pregunta (`routes/channels.php`,
                 * regla dura 18). Un origen cualquiera puede abrir el socket y no
                 * recibe absolutamente nada.
                 *
                 * Un cliente que quiera cerrarlo mas pone aqui su dominio: es
                 * configuracion de instalacion, no codigo (regla dura 13).
                 */
                'allowed_origins' => explode(',', (string) env('REVERB_ALLOWED_ORIGINS', '*')),

                'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
                'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                'max_connections' => env('REVERB_APP_MAX_CONNECTIONS'),
                'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),

                /*
                 * NADIE. Es lo mas importante de este fichero: el valor de serie
                 * —`members`— deja que un cliente suscrito publique mensajes que
                 * los demas reciben, y aqui eso significa que un panel podria
                 * inventarse que alguien ha fichado. El canal es de solo lectura
                 * y solo el servidor emite (ADR-011: «el WebSocket no es un canal
                 * de escritura»).
                 */
                'accept_client_events_from' => env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', 'none'),

                /*
                 * Techo por conexion. Encendido, al contrario que el valor de
                 * serie: un panel con un bucle —o una pestaña que reconecta sin
                 * espera— no puede consumir el proceso que atiende a los demas
                 * (RS-02, §7.1). Sesenta mensajes por minuto es un orden de
                 * magnitud mas de lo que un panel legitimo envia, que es
                 * practicamente nada.
                 */
                'rate_limiting' => [
                    'enabled' => env('REVERB_APP_RATE_LIMITING_ENABLED', true),
                    'max_attempts' => env('REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS', 60),
                    'decay_seconds' => env('REVERB_APP_RATE_LIMIT_DECAY_SECONDS', 60),
                    'terminate_on_limit' => env('REVERB_APP_RATE_LIMIT_TERMINATE', false),
                ],
            ],
        ],

    ],

];
