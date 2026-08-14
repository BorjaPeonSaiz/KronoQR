<?php

declare(strict_types=1);

/*
 * Spectator — validacion de peticiones y respuestas contra el contrato OpenAPI
 * (doc 02 §3.1 y §9.2, RQ-06, ADR-013).
 *
 * Esta configuracion NO es la publicada por el paquete: es la minima que este
 * proyecto necesita. Las claves que no aparecen las aporta el propio paquete al
 * fusionar su configuracion, asi que aqui solo esta lo que se decide distinto y
 * lo que conviene explicar.
 *
 * Lo que se decide distinto es la ruta del contrato. `docs/api/openapi.yaml`
 * vive FUERA de backend/ y a proposito: el contrato manda sobre los cuatro
 * artefactos que hablan HTTP —el backend y los tres frontends— y meterlo dentro
 * de uno de ellos lo convertiria en propiedad de ese uno.
 */

return [

    'default' => env('SPEC_SOURCE', 'local'),

    'sources' => [
        'local' => [
            'source' => 'local',

            /*
             * Una sola ruta relativa que vale en los dos sitios donde se ejecutan
             * las pruebas:
             *
             *   contenedor  /var/www/html/../docs/api  ->  /var/www/docs/api
             *               (infra/compose.dev.yaml monta ../docs en solo lectura)
             *   CI          <repo>/backend/../docs/api ->  <repo>/docs/api
             *
             * El paquete resuelve el `realpath` al abrir el fichero, asi que el
             * `..` no llega a ninguna parte. Si algun dia hiciera falta otra
             * ubicacion —un contrato servido por HTTP, por ejemplo—, SPEC_PATH la
             * sobrescribe sin tocar codigo.
             */
            'base_path' => env('SPEC_PATH', base_path('../docs/api')),
        ],
    ],

    /*
     * Las rutas del contrato se escriben enteras, con su `/api/v1` delante
     * (ADR-012: la version va en la ruta y es visible en el log, en la traza y
     * en un curl de diagnostico). Por eso aqui no hay prefijo que quitar: lo que
     * pide el cliente y lo que dice el contrato son la misma cadena.
     */
    'path_prefix' => '',

    /*
     * El middleware de validacion se antepone al grupo `api`, que es donde vive
     * routes/api_v1.php. Solo actua en las pruebas que llaman a Spectator::using().
     */
    'middleware_groups' => ['api'],

];
