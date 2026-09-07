<?php

declare(strict_types=1);

use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Los dos limitadores de las rutas publicas del emparejamiento** (RF-PD-06,
 * §7.1, regla dura 18).
 *
 * Estas dos rutas no tienen policy ni pueden tenerla —quien las llama es
 * precisamente el que todavia no tiene credencial—, asi que **el limitador ES la
 * autorizacion negativa que les corresponde**. Sin el:
 *
 * - `POST /kiosk/pair` deja llenar la tabla de solicitudes pendientes hasta
 *   agotar el espacio de codigos de seis digitos, que es la unica forma de negar
 *   desde fuera el alta de un quiosco.
 * - `POST /kiosk/pair/claim` deja probar secretos a la velocidad de la red.
 *
 * ## Lo que de verdad hay que probar es EL EJE, no el numero
 *
 * Que un contador corte a la decima peticion lo garantiza Laravel. Lo que no
 * garantiza nadie es **por que valor cuenta**, y ahi es donde una zona mal
 * escrita no se nota: si la clave del `claim` acabara saliendo de la IP —un
 * `by()` copiado de la zona de al lado—, en un hotel donde los cuatro quioscos
 * salen por la misma linea el limite no acotaria nada de lo que existe para
 * acotar, y ninguna prueba que solo cuente hasta el `429` lo veria.
 *
 * Por eso las dos pruebas que importan aqui son las que **cruzan los ejes**: la
 * misma IP con otra solicitud sigue teniendo cupo, y la misma solicitud desde
 * otra IP no.
 *
 * Los techos se bajan por configuracion (regla dura 13) en lugar de mandar cien
 * peticiones: lo que se comprueba es que la zona cuenta, no cuanto aguanta.
 */

uses(RefreshDatabase::class);

/**
 * Pide un codigo desde una IP concreta.
 *
 * @return array{pairing_id: string, pairing_secret: string}
 */
function solicitudDesde(string $ip): array
{
    /** @var array{pairing_id: string, pairing_secret: string} $ticket */
    $ticket = Api::guest()->fromIp($ip)->post('/api/v1/kiosk/pair', ['app_version' => '1.4.2'])->json();

    return $ticket;
}

it('corta a quien pide codigos de emparejamiento en rafaga desde la misma IP', function (): void {
    config()->set('kiosk.pairing.request_rate_per_ip', 2);

    WorkforceFixtures::site();

    Api::guest()->fromIp('203.0.113.10')->post('/api/v1/kiosk/pair', ['app_version' => '1.4.2'])->assertStatus(201);
    Api::guest()->fromIp('203.0.113.10')->post('/api/v1/kiosk/pair', ['app_version' => '1.4.2'])->assertStatus(201);

    Api::guest()->fromIp('203.0.113.10')
        ->post('/api/v1/kiosk/pair', ['app_version' => '1.4.2'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');
})->group('RF-PD-06', 'RS-02');

it('no deja sin codigo a la tablet de al lado cuando otra agota su cupo', function (): void {
    // La regla dura 19 por el otro lado: el techo protege la tabla, pero no puede
    // convertirse en la razon por la que un quiosco legitimo no se puede dar de
    // alta. Cada origen lleva su cuenta.
    config()->set('kiosk.pairing.request_rate_per_ip', 1);

    WorkforceFixtures::site();

    Api::guest()->fromIp('203.0.113.10')->post('/api/v1/kiosk/pair', ['app_version' => '1.4.2'])->assertStatus(201);
    Api::guest()->fromIp('203.0.113.10')->post('/api/v1/kiosk/pair', ['app_version' => '1.4.2'])->assertStatus(429);

    Api::guest()->fromIp('198.51.100.7')->post('/api/v1/kiosk/pair', ['app_version' => '1.4.2'])->assertStatus(201);
})->group('RF-PD-06', 'RS-02');

it('corta el sondeo repetido de la misma solicitud', function (): void {
    config()->set('kiosk.pairing.claim_rate_per_pairing', 2);

    WorkforceFixtures::site();

    $ticket = solicitudDesde('203.0.113.10');

    $sondeo = static fn (): mixed => Api::guest()->fromIp('203.0.113.10')->post('/api/v1/kiosk/pair/claim', [
        'pairing_id' => $ticket['pairing_id'],
        'pairing_secret' => $ticket['pairing_secret'],
    ]);

    $sondeo()->assertOk();
    $sondeo()->assertOk();

    $sondeo()->assertStatus(429)->assertHeader('Retry-After');
})->group('RF-PD-06', 'RS-02');

it('cuenta el sondeo por solicitud y no por origen', function (): void {
    // **EL EJE.** Todos los quioscos de un hotel salen por la misma linea: si el
    // cupo del `claim` fuera de la IP, el primero en sondear se lo comeria y los
    // demas no podrian recoger su token. Con la clave por `pairing_id`, la
    // solicitud agotada no arrastra a la de al lado.
    config()->set('kiosk.pairing.claim_rate_per_pairing', 1);

    WorkforceFixtures::site();

    $agotada = solicitudDesde('203.0.113.10');
    $vecina = solicitudDesde('203.0.113.10');

    Api::guest()->fromIp('203.0.113.10')->post('/api/v1/kiosk/pair/claim', [
        'pairing_id' => $agotada['pairing_id'],
        'pairing_secret' => $agotada['pairing_secret'],
    ])->assertOk();

    Api::guest()->fromIp('203.0.113.10')->post('/api/v1/kiosk/pair/claim', [
        'pairing_id' => $agotada['pairing_id'],
        'pairing_secret' => $agotada['pairing_secret'],
    ])->assertStatus(429);

    // Misma IP, otra solicitud: cupo intacto.
    Api::guest()->fromIp('203.0.113.10')->post('/api/v1/kiosk/pair/claim', [
        'pairing_id' => $vecina['pairing_id'],
        'pairing_secret' => $vecina['pairing_secret'],
    ])->assertOk();
})->group('RF-PD-06', 'RS-02');

it('sigue el cupo de una solicitud aunque el sondeo cambie de origen', function (): void {
    // La otra mitad del mismo eje, y la que cuenta para la seguridad: quien
    // intente adivinar un secreto no se libra del techo rotando de direccion.
    config()->set('kiosk.pairing.claim_rate_per_pairing', 1);

    WorkforceFixtures::site();

    $ticket = solicitudDesde('203.0.113.10');

    Api::guest()->fromIp('203.0.113.10')->post('/api/v1/kiosk/pair/claim', [
        'pairing_id' => $ticket['pairing_id'],
        'pairing_secret' => $ticket['pairing_secret'],
    ])->assertOk();

    Api::guest()->fromIp('198.51.100.7')->post('/api/v1/kiosk/pair/claim', [
        'pairing_id' => $ticket['pairing_id'],
        'pairing_secret' => $ticket['pairing_secret'],
    ])->assertStatus(429);
})->group('RF-PD-06', 'RS-02');
