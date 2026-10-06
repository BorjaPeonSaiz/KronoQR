<?php

declare(strict_types=1);

use App\Support\Http\ApplicationOrigin;

/*
 * CORS de la API: SOLO EL ORIGEN PROPIO (RS-09, hallazgo R4-SC-08 de la 2.2.0).
 *
 * POR QUE EXISTE ESTE FICHERO. Sin el, Laravel aplica el `cors.php` del
 * framework —`allowed_origins => ['*']`— y toda respuesta de `/api/v1/*` salia
 * con `Access-Control-Allow-Origin: *`, lleve o no `Origin` la peticion. Es el
 * segundo aviso del DAST (ZAP baseline). No habia nada explotable —la API se
 * autentica con token de portador, no con cookies—, pero tampoco ningun motivo:
 * NINGUN LLAMADOR LEGITIMO ES DE OTRO ORIGEN. Las tres SPA (quiosco, panel y
 * portal) se sirven desde el mismo host que la API, y en desarrollo el Vite de
 * cada una reenvia `/api` por su proxy, asi que el navegador tambien ve un unico
 * origen. Prometheus, blackbox y `doctor.sh` no envian `Origin` y CORS no les
 * afecta.
 *
 * EL ORIGEN SALE DE `APP_URL` (regla dura 13), normalizado por
 * {@see ApplicationOrigin} como lo escribe el navegador en la cabecera
 * `Origin`. Nada que configurar por cliente ni variable nueva en `.env`. Una
 * `APP_URL` vacia o ilegible deja la lista VACIA: ningun origen ajeno recibe
 * permiso y el propio no lo necesita (falla cerrado).
 *
 * POR QUE `allowed_origins_patterns` Y NO `allowed_origins`. Con un unico valor
 * en `allowed_origins`, `CorsService` lo escribe en `Access-Control-Allow-Origin`
 * en TODAS las respuestas, sin mirar la `Origin` recibida: seguro para el
 * navegador, pero anuncia el host a cualquiera y el DAST lo sigue viendo. Con un
 * patron entra en el modo dinamico: devuelve la `Origin` recibida SOLO si casa,
 * no pone nada en otro caso y añade `Vary: Origin`. El patron NO es un comodin:
 * es el origen literal escapado con `preg_quote` y anclado por los dos lados.
 *
 * `HandleCors` esta en la pila GLOBAL de Laravel 13 (no en la del grupo `api`) y
 * lee esta configuracion en cada peticion cuya ruta case con `paths`.
 */

return [

    // Solo la API. `sanctum/csrf-cookie` no se usa: la autenticacion es por token.
    'paths' => ['api/*'],

    // Los metodos que declara el contrato (`docs/api/openapi.yaml`).
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],

    // Vacia a proposito: ver arriba por que el origen va como patron.
    'allowed_origins' => [],

    'allowed_origins_patterns' => ApplicationOrigin::corsPatternsFor(env('APP_URL')),

    // Las que envian las tres SPA; `Idempotency-Key` es la del fichaje (regla dura 8).
    'allowed_headers' => ['Accept', 'Accept-Language', 'Authorization', 'Content-Type', 'Idempotency-Key'],

    // Ningun llamador de otro origen tiene que leer cabeceras propias.
    'exposed_headers' => [],

    'max_age' => 0,

    // Igual que el valor de serie: la API se autentica con token de portador, no
    // con cookies, y no hay credenciales que compartir entre origenes.
    'supports_credentials' => false,

];
