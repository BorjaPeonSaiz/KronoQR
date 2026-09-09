<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Zona `management`: techo de peticiones de la API de gestion** (§7.1, RS-02,
 * RF-ID-03).
 *
 * ## Por que estas rutas y no todas
 *
 * Las cubiertas son las cuatro que pasan por `ScopeGuard` —listado de plantilla,
 * ficha, registro horario de una persona y correcciones—, que son las unicas de la
 * API en las que una peticion **denegada** escribe en `audit_log`. Ese asiento toma
 * el `pg_advisory_xact_lock` **global** de ADR-010, el mismo por el que pasa cada
 * fichaje: sin techo, un bucle sobre UUID ajenos mete escrituras serializadas en el
 * camino critico del cambio de turno.
 *
 * **Y desde la 5.11b, tambien las de credenciales** (revision de seguridad): la
 * hoja de instrucciones, `print` y `print-batch` lanzan un Chromium por
 * peticion, que es un coste de maquina —CPU y memoria— y no de base de datos.
 * El motivo es distinto; la zona, la misma, porque lo que se acota es lo que el
 * servidor del cliente puede sostener mientras se ficha.
 *
 * ## Es distinto del que pone Nginx, y del que agrupa el trail
 *
 * Nginx limita **por origen** y no lee el token, asi que en un hotel —donde toda la
 * gestion sale por la misma linea— no puede distinguir dos sesiones. Esta zona
 * limita **por cuenta y por origen**. Y ninguno de los dos sustituye a la
 * agrupacion de denegaciones de `GroupedAuthorizationJournal`: aquellos acotan
 * cuantas peticiones se atienden, y esta cuantas **filas** se escriben.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('identity.two_factor.required_roles', []);
});

it('corta a la cuenta que supera su techo de peticiones de gestion', function (): void {
    config()->set('identity.management.rate_limit_per_minute', 2);

    WorkforceFixtures::site();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($token)->get('/api/v1/employees')->assertStatus(200);
    Api::as($token)->get('/api/v1/employees')->assertStatus(200);

    Api::as($token)->get('/api/v1/employees')
        ->assertStatus(429)
        ->assertHeader('Retry-After');
})->group('RS-02', 'RF-ID-03');

it('cuenta el techo de gestion por cuenta y no solo por origen', function (): void {
    // EL EJE QUE NGINX NO PUEDE APLICAR. El borde limita por IP y no lee el token,
    // asi que un bucle que rotara de direccion —o que saliera por una VPN— pasaria
    // por debajo de su techo entero. Aqui no: el cupo es de la cuenta y la sigue a
    // donde vaya.
    config()->set('identity.management.rate_limit_per_minute', 2);

    WorkforceFixtures::site();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    foreach (range(1, 2) as $ignored) {
        Api::as($token)->fromIp('10.0.0.9')->get('/api/v1/employees')->assertStatus(200);
    }

    // Otra direccion, misma cuenta: el cupo ya esta gastado.
    Api::as($token)->fromIp('10.0.0.10')->get('/api/v1/employees')->assertStatus(429);

    // Y otra cuenta desde una tercera direccion sigue trabajando: el techo acota a
    // quien se pasa, no a la instalacion.
    $otra = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($otra)->fromIp('10.0.0.11')->get('/api/v1/employees')->assertStatus(200);
})->group('RS-02', 'RF-ID-03');

it('cubre con el mismo cupo el listado, el registro horario ajeno y las correcciones', function (): void {
    // Las cuatro puertas por las que se llega a `ScopeGuard` comparten zona, y por
    // tanto cupo. Un techo por ruta seria un techo que se rodea alternando URL:
    // las cuatro escriben el mismo asiento contra el mismo candado.
    config()->set('identity.management.rate_limit_per_minute', 2);

    $site = WorkforceFixtures::site();
    $empleado = WorkforceFixtures::employee($site);

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($token)->get('/api/v1/employees/'.$empleado.'/workdays')->assertStatus(200);

    // Cuerpo vacio a proposito: lo que importa aqui es que la peticion CONSUME
    // cupo, y un `422` demuestra que llego al validador, es decir que paso el
    // limitador.
    Api::as($token)->post('/api/v1/shift-entries', [])->assertStatus(422);

    Api::as($token)->get('/api/v1/employees/'.$empleado)->assertStatus(429);
})->group('RS-02', 'RF-ID-03', 'RF-PA-04');

it('cubre tambien las rutas de credenciales, que lanzan un Chromium por peticion', function (): void {
    /*
     * **Hallazgo de la revision de seguridad de la 5.11b.** El grupo
     * `/api/v1/credentials` no declaraba zona de limite de APLICACION: lo unico
     * que lo frenaba era Nginx, que cuenta por origen y no lee el token, asi que
     * todo el hotel compartia contador y ninguna cuenta tenia techo propio.
     *
     * Y lo que hay detras de tres de esas rutas —la hoja de instrucciones,
     * `print` y `print-batch`— no es un `SELECT`: es **un Chromium por
     * peticion**, que compite por CPU y memoria con `/scan` en la misma maquina.
     * Las otras tres escriben en `audit_log`, que toma el candado global de
     * ADR-010.
     *
     * La tercera peticion es la HOJA a proposito: el `429` se resuelve en el
     * middleware, antes del controlador, asi que demuestra justamente lo que
     * interesa —que el navegador sin cabeza no llega a arrancar—.
     */
    config()->set('identity.management.rate_limit_per_minute', 2);

    WorkforceFixtures::site();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($token)->get('/api/v1/credentials/status')->assertStatus(200);
    Api::as($token)->get('/api/v1/credentials/status')->assertStatus(200);

    Api::as($token)->get('/api/v1/credentials/instructions-sheet')
        ->assertStatus(429)
        ->assertHeader('Retry-After');
})->group('RS-02', 'RF-QR-04', 'RL-05');

it('comparte el cupo entre la gestion de plantilla y la de credenciales', function (): void {
    // Dos ambitos distintos —`employees:*` y `credentials:*`— y una sola zona, y
    // eso es deliberado: el coste que se acota es el de la INSTALACION, no el de
    // un endpoint. Un techo por grupo seria un techo que se rodea alternando
    // entre los dos.
    config()->set('identity.management.rate_limit_per_minute', 2);

    WorkforceFixtures::site();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($token)->get('/api/v1/employees')->assertStatus(200);
    Api::as($token)->get('/api/v1/credentials/status')->assertStatus(200);

    Api::as($token)->get('/api/v1/credentials/status')->assertStatus(429);
})->group('RS-02', 'RF-QR-04');
