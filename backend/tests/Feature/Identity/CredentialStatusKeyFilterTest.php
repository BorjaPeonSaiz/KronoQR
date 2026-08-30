<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Command\RotateSigningKeyCommand;
use App\Modules\Identity\Application\UseCase\RotateSigningKey;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\Credentials;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET /api/v1/credentials/status?key_id=` — **a quien le falta reimprimir**
 * (RF-QR-07, RF-QR-08, doc 02 §5.3, tarea 2.12).
 *
 * Es la mitad de lectura de la rotacion con solape. La otra —rotar y retirar—
 * no tiene endpoint a proposito y vive en `credentials:rotate-key`: rotar la
 * clave es un acto operativo con semanas de logistica detras, no un boton.
 *
 * Lo que este endpoint tiene que responder, y lo que se prueba aqui:
 *
 * 1. Quien sigue fichando con la clave saliente, para ir a buscarle.
 * 2. Cuando ya no queda nadie, que es lo que autoriza `credentials:retire-key`.
 * 3. Que el `summary` **no** se filtra: sigue diciendo «12 de 60».
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * Una plantilla de `$employees` personas con tarjeta impresa de la clave
 * saliente, ya rotada: cada una tiene su tarjeta vieja viva y su reemision
 * pendiente de imprimir.
 *
 * @return array{token: string, employees: list<int>}
 */
function plantillaEnRotacion(int $employees = 3): array
{
    $site = WorkforceFixtures::site();
    $ids = [];

    for ($i = 0; $i < $employees; $i++) {
        $uuid = WorkforceFixtures::employee($site);
        /** @var int $id */
        $id = DB::table('employees')->where('uuid', $uuid)->value('id');

        Credentials::issueFor($id, Credentials::previousKey());
        $ids[] = $id;
    }

    app(RotateSigningKey::class)->handle(new RotateSigningKeyCommand);

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'employees' => $ids,
    ];
}

it('devuelve a quien le falta reimprimir y respeta el contrato', function (): void {
    $contexto = plantillaEnRotacion();

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/credentials/status?key_id=a2')
        ->assertValidResponse(200);

    expect($respuesta->json('data'))->toHaveCount(3)
        // El denominador NO se filtra: «faltan 3 de 3» seria el numero inutil.
        ->and($respuesta->json('summary.employees'))->toBe(3)
        ->and($respuesta->json('summary.retiring_key_id'))->toBe('a2')
        ->and($respuesta->json('summary.pending_reprint'))->toBe(3)
        // Estas personas SI pueden fichar: llevan encima su tarjeta vieja, que
        // durante el solape sigue valiendo. Contarlas como «sin tarjeta» seria
        // una alerta sin problema detras.
        ->and($respuesta->json('summary.without_delivered_credential'))->toBe(3);

    // Y la fila que se devuelve es la de la tarjeta EN USO, la firmada con la
    // clave saliente, no la reemision que espera turno de impresora.
    expect($respuesta->json('data.0.credential.key_id'))->toBe('a2');
})->group('RF-QR-07', 'RF-QR-08');

it('deja de devolver a quien ya lleva la tarjeta nueva', function (): void {
    // El avance de la reimpresion, que es lo que el panel pinta. Cuando esta
    // lista se vacia, `credentials:retire-key` deja de negarse.
    $contexto = plantillaEnRotacion();

    // El relevo de una persona: se le entrego la nueva y la vieja se revoco.
    DB::table('credentials')
        ->where('employee_id', $contexto['employees'][0])
        ->where('key_id', 'a2')
        ->update(['revoked_at' => '2026-08-30 06:00:00+00', 'revoked_reason' => 'Rotacion']);

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/credentials/status?key_id=a2')
        ->assertValidResponse(200);

    expect($respuesta->json('data'))->toHaveCount(2)
        ->and($respuesta->json('summary.pending_reprint'))->toBe(2);
})->group('RF-QR-07', 'RF-QR-08');

it('no dice si una clave existe o no: la que no conoce nadie devuelve la lista vacia', function (): void {
    // Ni `404` ni `422`. Comprobarlo contra el llavero convertiria el parametro
    // en un oraculo de que claves tiene configuradas la instalacion.
    $contexto = plantillaEnRotacion();

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/credentials/status?key_id=zz')
        ->assertValidResponse(200);

    expect($respuesta->json('data'))->toBe([])
        ->and($respuesta->json('summary.employees'))->toBe(3);
})->group('RF-QR-07', 'RS-03');

it('rechaza un key_id con forma invalida', function (string $keyId): void {
    $contexto = plantillaEnRotacion(1);

    Api::as($contexto['token'])
        ->get('/api/v1/credentials/status?key_id='.$keyId)
        ->assertValidResponse(422);
})->with([
    'demasiado largo' => ['a2b'],
    'con simbolos' => ['a-'],
    'de un solo caracter' => ['a'],
])->group('RF-QR-07', 'RF-QR-08');

it('fuera de una rotacion el resumen dice que no hay ninguna', function (): void {
    // La serie se escribe igualmente, a cero y con la clave a `null`: un panel
    // no puede distinguir un recuento ausente de uno en cero.
    $site = WorkforceFixtures::site();
    $uuid = WorkforceFixtures::employee($site);
    /** @var int $employeeId */
    $employeeId = DB::table('employees')->where('uuid', $uuid)->value('id');
    Credentials::issueFor($employeeId);

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    $respuesta = Api::as($token)
        ->get('/api/v1/credentials/status')
        ->assertValidResponse(200);

    // La suite si tiene solape configurado (`phpunit.xml`), asi que
    // `retiring_key_id` es `a2`; lo que vale cero es el recuento, porque nadie
    // ficha con ella.
    expect($respuesta->json('summary.pending_reprint'))->toBe(0)
        ->and($respuesta->json('summary.retiring_key_id'))->toBe('a2');
})->group('RF-QR-08');

it('el comando de consola responde a la misma pregunta que el endpoint', function (): void {
    // `credentials:status --key-id=` es la via del operador durante la rotacion,
    // y la que el runbook manda ejecutar antes de retirar la clave.
    $contexto = plantillaEnRotacion(1);

    Artisan::call('credentials:status', ['--key-id' => 'a2', '--no-metrics' => true]);

    expect(Artisan::output())->toContain('Rotacion en curso');

    DB::table('credentials')
        ->where('key_id', 'a2')
        ->update(['revoked_at' => '2026-08-30 06:00:00+00', 'revoked_reason' => 'Rotacion']);

    Artisan::call('credentials:status', ['--key-id' => 'a2', '--no-metrics' => true]);

    expect(Artisan::output())->toContain('credentials:retire-key a2');
})->group('RF-QR-07', 'RF-QR-08');
