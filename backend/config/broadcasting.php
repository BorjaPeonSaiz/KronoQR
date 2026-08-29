<?php

declare(strict_types=1);

/*
 * Difusion en tiempo real (ADR-011, doc 02 §3.1).
 *
 * RECORTADO A LO QUE EXISTE. El fichero de serie de Laravel trae ademas `pusher`,
 * `ably` y `redis`. Los tres se han quitado: los dos primeros son servicios
 * gestionados que sacarian datos de presencia de la plantilla del cliente a un
 * tercero —ADR-011 los descarta por escrito, y RL-14 lo prohibe—, y el tercero
 * exige un cliente WebSocket propio que este producto no tiene. Una conexion que
 * no se puede elegir es una conexion que no debe estar escrita.
 *
 * EL CANAL NO ES UN CANAL DE ESCRITURA. Por aqui no viaja ningun fichaje, ninguna
 * correccion y ninguna accion con relevancia legal: solo la vista de presencia
 * (ADR-011). Si algun dia hiciera falta escribir, se escribe por HTTP.
 *
 * Y NO PUEDE BLOQUEAR EL FICHAJE (reglas duras 15 y 19). La difusion sale de un
 * listener encolado que ademas atrapa sus propios fallos; un Reverb parado deja
 * el panel sondeando cada 15 s y el registro horario intacto.
 */

return [

    /*
     * `reverb` en produccion y en desarrollo, `null` en la suite de pruebas
     * (phpunit.xml). Que la suite no difunda no es una limitacion: lo que hay
     * que comprobar es que el evento se emite con su canal y su contenido, y eso
     * se verifica sobre el evento, no sobre un socket abierto.
     */
    'default' => env('BROADCAST_CONNECTION', 'reverb'),

    'connections' => [

        /*
         * Reverb, autoalojado y sin coste por mensaje (ADR-011). Corre como un
         * proceso mas del `docker compose` y funciona sin salida a internet, que
         * es requisito del §6.7: hay instalaciones en red aislada.
         *
         * `key` es PUBLICA —identifica la aplicacion en el saludo del WebSocket y
         * viaja al navegador en `meta.realtime.key`— y `secret` NO SALE DEL
         * SERVIDOR: con el se firma la autorizacion de cada canal privado y las
         * llamadas a la API de Reverb. Las dos se generan en la instalacion; el
         * repositorio no lleva ninguna (`.env.example`).
         *
         * `host`, `port` y `scheme` son los del SERVIDOR hablando con Reverb por
         * la red interna de Docker, no los del navegador: el panel llega por el
         * mismo origen a traves de Nginx, que proxifica `/app/` y `/apps/`.
         */
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST', 'reverb'),
                // `(int)` y no el valor crudo de `env()`, que llega como CADENA:
                // el repositorio lee esta clave con `Config::integer()` —que es
                // estricto a proposito— y sin el casting la consulta de
                // `websocket_connections_active` se cae con «must be an integer,
                // string given» solo en produccion, donde el valor viene del
                // fichero de entorno y no del valor por defecto.
                'port' => (int) env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'client_options' => [
                /*
                 * Tres segundos, y no los treinta de serie del cliente de Pusher.
                 * Esta llamada la hace un trabajo de cola justo detras de un
                 * fichaje: si Reverb esta parado, hay que rendirse rapido y
                 * seguir. Media hora de trabajos colgados esperando a un socket
                 * muerto es como se atasca la cola que ademas drena la
                 * sincronizacion offline de los quioscos.
                 */
                'timeout' => 3,
            ],
        ],

        /*
         * Para diagnosticar en una instalacion: escribe cada mensaje en el log en
         * lugar de enviarlo. **No usar con datos reales sin pensarlo**: el mensaje
         * de presencia lleva nombres, y un log con nombres viaja al fabricante
         * dentro del paquete de diagnostico (regla dura 21, ADR-020). Sirve para
         * ver que la difusion ocurre; para ver que contiene, esta la prueba.
         */
        'log' => [
            'driver' => 'log',
        ],

        /*
         * Descarta. Es el de la suite de pruebas y el de una instalacion que
         * apaga el tiempo real: `meta.realtime.enabled` sale `false` y el panel
         * sondea (ADR-011, ADR-023).
         */
        'null' => [
            'driver' => 'null',
        ],

    ],

];
