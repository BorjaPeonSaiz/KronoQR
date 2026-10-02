<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\Credentials;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LA FECHA DE CESE NO PUEDE SER POSTERIOR A HOY EN EL CENTRO** (RN-14,
 * RF-GP-03, 2.2.0; hallazgos R4-SC-01, R3-AR-01, R3-BE-01, R3-PA-06).
 *
 * La baja es efectiva al registrarla: revoca las tarjetas y corta el fichaje en
 * el acto. Con una fecha de cese futura, la persona se quedaba sin fichar ni
 * poder anotar su jornada hasta el cese. Ahora una fecha posterior a hoy
 * responde `422` en `terminated_at` y **no cambia nada**: ni la ficha, ni las
 * tarjetas, ni `audit_log`.
 *
 * «Hoy» es la fecha civil del centro (`sites.timezone`) en el instante en que
 * llega la baja, y el reloj se detiene con `FrozenTime` para probar las
 * fronteras: a las 23:30 UTC del dia 2 en Canarias ya es el dia 3; a las 21:30
 * UTC del dia 2 en Madrid todavia es el 2.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

function tokenDeLaBajaConFecha(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
}

/**
 * Una persona en alta desde el 2026-01-01 (`WorkforceFixtures`), con una tarjeta
 * activa, en un centro de la zona indicada.
 */
function personaParaLaBajaConFecha(string $timezone = 'Europe/Madrid'): string
{
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site('Hotel de la fecha de cese', $timezone));

    /** @var int $id */
    $id = DB::table('employees')->where('uuid', $uuid)->value('id');
    Credentials::issueFor($id);

    return $uuid;
}

/**
 * Lo que una baja rechazada no puede haber tocado.
 *
 * @return array{employee: array<string, mixed>, active_cards: int, audit_entries: int}
 */
function estadoAntesDeLaBajaConFecha(string $uuid): array
{
    /** @var object $row */
    $row = DB::table('employees')->where('uuid', $uuid)->first();
    /** @var int $id */
    $id = DB::table('employees')->where('uuid', $uuid)->value('id');

    return [
        'employee' => (array) $row,
        'active_cards' => DB::table('credentials')->where('employee_id', $id)->whereNull('revoked_at')->count(),
        'audit_entries' => DB::table('audit_log')->count(),
    ];
}

it('rechaza un cese posterior a hoy con 422 en terminated_at y no cambia nada', function (): void {
    FrozenTime::at('2026-10-02 10:00:00');
    $persona = personaParaLaBajaConFecha();
    $antes = estadoAntesDeLaBajaConFecha($persona);

    Api::as(tokenDeLaBajaConFecha())
        ->post('/api/v1/employees/'.$persona.'/offboard', ['terminated_at' => '2026-10-31', 'reason' => 'Fin de contrato'])
        ->assertValidRequest()
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed')
        ->assertJsonPath('errors.terminated_at.0', 'La fecha de cese (2026-10-31) es posterior a hoy (2026-10-02). La baja es '
            .'efectiva al registrarla; regístrala el último día, cuando haya terminado su turno.');

    // Nada a medias: la ficha sigue igual, la tarjeta activa y ningun asiento.
    expect(estadoAntesDeLaBajaConFecha($persona))->toBe($antes)
        ->and($antes['employee']['status'])->toBe('active')
        ->and($antes['active_cards'])->toBe(1);
})->group('RN-14', 'RF-GP-03');

it('dice lo mismo en ingles a quien pide ingles', function (): void {
    FrozenTime::at('2026-10-02 10:00:00');
    $persona = personaParaLaBajaConFecha();
    $antes = estadoAntesDeLaBajaConFecha($persona);

    Api::as(tokenDeLaBajaConFecha())
        ->withHeaders(['Accept-Language' => 'en'])
        ->post('/api/v1/employees/'.$persona.'/offboard', ['terminated_at' => '2026-10-03'])
        ->assertValidResponse(422)
        ->assertJsonPath('errors.terminated_at.0', 'The termination date (2026-10-03) is later than today (2026-10-02). '
            .'Offboarding takes effect as soon as it is recorded; record it on the last day, once the shift is over.');

    expect(estadoAntesDeLaBajaConFecha($persona))->toBe($antes);
})->group('RN-14', 'RF-GP-03');

it('resuelve hoy con la fecha civil del centro y no con la UTC', function (string $ahora, string $zona, string $cese, int $estado): void {
    FrozenTime::at($ahora);
    $persona = personaParaLaBajaConFecha($zona);

    Api::as(tokenDeLaBajaConFecha())
        ->post('/api/v1/employees/'.$persona.'/offboard', ['terminated_at' => $cese])
        ->assertValidResponse($estado);

    expect(DB::table('employees')->where('uuid', $persona)->value('status'))->toBe($estado === 200 ? 'terminated' : 'active');
})->with([
    // 23:30 UTC del dia 2 son las 00:30 del dia 3 en Canarias: el 3 ya es hoy.
    'Canarias a las 00:30 del dia 3, cese el 3' => ['2026-10-02 23:30:00', 'Atlantic/Canary', '2026-10-03', 200],
    // 21:30 UTC del dia 2 son las 23:30 del dia 2 en Madrid: el 3 es mañana.
    'Madrid a las 23:30 del dia 2, cese el 3' => ['2026-10-02 21:30:00', 'Europe/Madrid', '2026-10-03', 422],
    'cese hoy' => ['2026-10-02 10:00:00', 'Europe/Madrid', '2026-10-02', 200],
    'cese el dia del alta' => ['2026-10-02 10:00:00', 'Europe/Madrid', '2026-01-01', 200],
    'cese el dia anterior al alta' => ['2026-10-02 10:00:00', 'Europe/Madrid', '2025-12-31', 422],
])->group('RN-14', 'RF-GP-03');

it('a una persona que aun no ha empezado solo la da de baja en su fecha de alta', function (): void {
    FrozenTime::at('2026-10-02 10:00:00');
    $persona = personaParaLaBajaConFecha();
    DB::table('employees')->where('uuid', $persona)->update(['hired_at' => '2026-10-15']);
    $token = tokenDeLaBajaConFecha();

    Api::as($token)
        ->post('/api/v1/employees/'.$persona.'/offboard', ['terminated_at' => '2026-10-02'])
        ->assertValidResponse(422)
        ->assertJsonPath('errors.terminated_at.0', 'Esta persona aún no ha empezado a trabajar (alta el 2026-10-15). '
            .'La única fecha de cese posible es la de alta, 2026-10-15.');

    Api::as($token)
        ->post('/api/v1/employees/'.$persona.'/offboard', ['terminated_at' => '2026-10-15'])
        ->assertValidResponse(200)
        ->assertJsonPath('status', 'terminated')
        ->assertJsonPath('terminated_at', '2026-10-15');
})->group('RN-14', 'RF-GP-03');

it('sin centro configurado no da de baja a nadie y no cae a UTC', function (): void {
    // R-4: sin la zona del centro no hay «hoy» que resolver. Un estado de la
    // instalacion, no un error de la peticion: 409.
    FrozenTime::at('2026-10-02 10:00:00');

    Api::as(tokenDeLaBajaConFecha())
        ->post('/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90/offboard', ['terminated_at' => '2026-10-02'])
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:conflict');

    expect(DB::table('audit_log')->where('action', 'employee.offboarded')->count())->toBe(0);
})->group('RN-14', 'RF-GP-03');
