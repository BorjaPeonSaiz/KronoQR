<?php

declare(strict_types=1);

/*
 * Lo que el panel necesita saber del tiempo real, y que **viaja al cliente**
 * (ADR-011, ADR-017, regla dura 13).
 *
 * POR QUE UN FICHERO PROPIO Y NO UNA CLAVE MAS EN broadcasting.php. Porque no
 * describe como difunde el servidor: describe como se conecta el navegador, y
 * son dos cosas distintas. `broadcasting.php` habla de `reverb:8080` por la red
 * interna de Docker; esto habla de `/app` en el mismo origen desde el que se
 * sirvio el panel.
 *
 * Y POR QUE NO SON VARIABLES `VITE_*`. El panel se compila una vez y se instala
 * en el servidor de cada cliente. Una clave o un puerto dentro del paquete
 * obligarian a recompilar la SPA por instalacion, que es exactamente lo que
 * ADR-017 prohibe. Estos valores salen en `meta.realtime` de
 * `GET /api/v1/attendance/live`, con la primera respuesta que el panel ya iba a
 * pedir.
 */

return [

    /*
     * Interruptor de instalacion. Apagarlo NO apaga la vista: la deja en sondeo
     * cada `poll_interval_seconds`, con aviso en pantalla (RNF-D-03). Es la
     * degradacion honesta del ADR-011 y la unica degradacion parcial de ADR-023.
     *
     * Sirve para un cliente cuyo proxy corporativo rompe los WebSockets: en vez
     * de dejar al panel reintentando contra un socket que nunca abre, se apaga y
     * sondea desde el primer segundo.
     */
    'enabled' => env('REALTIME_ENABLED', true),

    /*
     * Ruta del WebSocket en el mismo origen del panel. La proxifica Nginx hacia
     * el contenedor de Reverb (`infra/docker/nginx/templates/kronoqr.conf.template`).
     * Si se cambia aqui, se cambia alli: son el mismo camino.
     */
    'path' => env('REALTIME_PATH', '/app'),

    /*
     * Donde se firma la suscripcion a un canal privado. Cuelga de `/api/v1` como
     * todo lo demas (ADR-012) y se llama con el mismo token Bearer que el resto
     * de la API: el panel es una SPA con token, no una sesion de cookie. La ruta
     * se registra en `bootstrap/app.php` con este prefijo.
     */
    'auth_endpoint' => env('REALTIME_AUTH_ENDPOINT', '/api/v1/broadcasting/auth'),

    /*
     * Nombre del evento al que se suscribe el panel. Estable y desacoplado del
     * nombre de la clase PHP: renombrar o mover el fichero del evento no puede
     * romper tres frontends sin que nada falle en el servidor.
     */
    'event' => env('REALTIME_EVENT', 'presence.updated'),

    /*
     * Cada cuanto sondear cuando no hay WebSocket. QUINCE SEGUNDOS, y la cifra es
     * el requisito: RNF-D-03 y ADR-011 la fijan. Lo decide el servidor y no el
     * panel porque es carga contra la misma base de datos que atiende el camino
     * critico del fichaje (RNF-P-02): con tres puestos abiertos, bajarlo a 5 s
     * triplica esa carga justo en el cambio de turno.
     */
    // `(int)` y no el valor crudo de `env()`: una variable de entorno llega
    // siempre como CADENA, y el repositorio la lee con `Config::integer()`, que es
    // estricto a proposito. Sin el casting, el endpoint se cae con «must be an
    // integer, string given» solo donde la variable esta declarada —es decir, en
    // la instalacion de un cliente y no en la suite—.
    'poll_interval_seconds' => (int) env('REALTIME_POLL_INTERVAL_SECONDS', 15),

];
