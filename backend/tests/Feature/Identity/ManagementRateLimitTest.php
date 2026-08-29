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
