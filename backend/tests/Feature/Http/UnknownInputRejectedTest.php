<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;

/*
 * Las operaciones sin campos propios rechazan lo que les llegue (CT2 de la
 * verificacion de la 2.1.0).
 *
 * Su `FormRequest` usa `RejectsUnknownInput` con `rules()` vacio, y el rasgo lee
 * `$this->all()`: un campo de cuerpo **o un parametro de consulta** que la
 * operacion no admite es un `422 validation-failed` con su nombre en `errors`.
 * Lo hacian desde que existen y el contrato no lo declaraba; desde la 2.2.1 lo
 * declaran con `UnknownInputRejected`, y aqui se valida contra el contrato.
 *
 * El `uuid` de la ruta no existe a proposito: la validacion va antes que el caso
 * de uso, y lo que se prueba es esa puerta, no lo que hay detras.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    FrozenTime::at('2026-03-14 09:00:00');
});

const UNKNOWN_INPUT_REJECTED_UUID = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90';

/**
 * La peticion con un campo que la operacion no admite, desde una sesion de
 * administracion, y el `422` del contrato con el nombre del campo.
 */
function campoDesconocido(string $metodo, string $ruta, string $campo): void
{
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->call($metodo, $ruta, [$campo => 'x'])
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed')
        ->assertJsonStructure(['errors' => [$campo]]);
}

// Un dataset por requisito: la etiqueta de un dataset se aplica a todas sus
// filas, y mezclar quioscos y PIN inflaria la matriz de trazabilidad.

it('responde con el 422 del contrato a un campo que la operacion de quioscos no admite', function (string $metodo, string $ruta, string $campo): void {
    campoDesconocido($metodo, $ruta, $campo);
})->with([
    'lista de quioscos, por la consulta' => ['GET', '/api/v1/devices', 'status'],
    'desvincular un quiosco' => ['POST', '/api/v1/devices/'.UNKNOWN_INPUT_REJECTED_UUID.'/unpair', 'reason'],
])->group('RF-PD-06');

it('responde con el 422 del contrato a un campo que la operacion del PIN no admite', function (string $metodo, string $ruta, string $campo): void {
    campoDesconocido($metodo, $ruta, $campo);
})->with([
    'restablecer el PIN' => ['POST', '/api/v1/employees/'.UNKNOWN_INPUT_REJECTED_UUID.'/pin/reset', 'pin'],
    'registrar la entrega del PIN' => ['POST', '/api/v1/employees/'.UNKNOWN_INPUT_REJECTED_UUID.'/pin/deliver', 'delivered_by'],
])->group('RF-ID-09');

it('responde con el 422 del contrato a un campo en el alta del segundo factor', function (): void {
    // Con la sesion pendiente de segundo factor, que es la unica que puede pedir
    // el alta: con otra la puerta seria el `403` y no se llegaria a validar.
    $pendiente = ManagementUsers::pendingTokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($pendiente)
        ->post('/api/v1/auth/2fa/enrol', ['secret' => 'JBSWY3DPEHPK3PXP'])
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed')
        ->assertJsonStructure(['errors' => ['secret']]);
})->group('RF-ID-01', 'RS-06');
