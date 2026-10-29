<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\Credentials;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\InstallationLocale;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LA FECHA DE CESE DECIDE SI LA BAJA ES EN EL ACTO O PROGRAMADA** (RN-14,
 * RF-GP-03, ADR-062 §3).
 *
 * Anterior a hoy, en el acto. Hoy o posterior, programada: la persona sigue en
 * alta y ficha hasta su ultimo dia. «Aplicar ya» hace efectiva en el acto la de
 * hoy, y con una fecha futura responde `422` en `apply_now` **sin cambiar nada**:
 * ni la ficha, ni las tarjetas, ni `audit_log`.
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

it('programa la baja con un cese futuro: sigue en alta, con su tarjeta y su asiento de baja programada', function (): void {
    FrozenTime::at('2026-10-02 10:00:00');
    $persona = personaParaLaBajaConFecha();

    Api::as(tokenDeLaBajaConFecha())
        ->post('/api/v1/employees/'.$persona.'/offboard', ['terminated_at' => '2026-10-31', 'reason' => 'Fin de contrato'])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('terminated_at', '2026-10-31')
        ->assertJsonPath('offboarding.state', 'scheduled')
        ->assertJsonPath('offboarding.terminated_at', '2026-10-31');

    $estado = estadoAntesDeLaBajaConFecha($persona);

    expect($estado['employee']['status'])->toBe('active')
        ->and($estado['active_cards'])->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'employee.offboarding_scheduled')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'employee.offboarded')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'credential.revoked')->count())->toBe(0);
})->group('RN-14', 'RF-GP-03');

it('rechaza aplicar ya un cese futuro con 422 en apply_now y no cambia nada', function (): void {
    FrozenTime::at('2026-10-02 10:00:00');
    $persona = personaParaLaBajaConFecha();
    $antes = estadoAntesDeLaBajaConFecha($persona);

    Api::as(tokenDeLaBajaConFecha())
        ->post('/api/v1/employees/'.$persona.'/offboard', ['terminated_at' => '2026-10-31', 'apply_now' => true])
        ->assertValidRequest()
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed')
        ->assertJsonPath('errors.apply_now.0', '«Aplicar ya» solo vale con una fecha de cese de hoy o anterior, y el '
            .'último día (2026-10-31) aún no ha llegado (hoy es 2026-10-02). Quítalo para programar la baja, o pon la '
            .'fecha de hoy.');

    expect(estadoAntesDeLaBajaConFecha($persona))->toBe($antes)
        ->and($antes['employee']['status'])->toBe('active')
        ->and($antes['employee']['terminated_at'])->toBeNull()
        ->and($antes['active_cards'])->toBe(1);
})->group('RN-14', 'RF-GP-03');

it('dice lo mismo en ingles a quien pide ingles', function (): void {
    // Con el ingles activo: de serie solo lo esta el español (bloque 26).
    InstallationLocale::set('es', ['es', 'en']);
    FrozenTime::at('2026-10-02 10:00:00');
    $persona = personaParaLaBajaConFecha();
    $antes = estadoAntesDeLaBajaConFecha($persona);

    Api::as(tokenDeLaBajaConFecha())
        ->withHeaders(['Accept-Language' => 'en'])
        ->post('/api/v1/employees/'.$persona.'/offboard', ['terminated_at' => '2026-10-03', 'apply_now' => true])
        ->assertValidResponse(422)
        ->assertJsonPath('errors.apply_now.0', '"Apply now" only works with a termination date of today or earlier, '
            .'and the last day (2026-10-03) has not arrived yet (today is 2026-10-02). Clear it to schedule the '
            .'offboarding, or set today\'s date.');

    expect(estadoAntesDeLaBajaConFecha($persona))->toBe($antes);
})->group('RN-14', 'RF-GP-03');

it('resuelve hoy con la fecha civil del centro y no con la UTC', function (string $ahora, string $zona, string $cese, bool $aplicarYa, int $codigo, string $estado): void {
    FrozenTime::at($ahora);
    $persona = personaParaLaBajaConFecha($zona);

    Api::as(tokenDeLaBajaConFecha())
        ->post('/api/v1/employees/'.$persona.'/offboard', ['terminated_at' => $cese, 'apply_now' => $aplicarYa])
        ->assertValidResponse($codigo);

    expect(DB::table('employees')->where('uuid', $persona)->value('status'))->toBe($estado);
})->with([
    // 23:30 UTC del dia 2 son las 00:30 del dia 3 en Canarias: el 3 ya es hoy.
    'Canarias a las 00:30 del dia 3, aplicar ya el 3' => ['2026-10-02 23:30:00', 'Atlantic/Canary', '2026-10-03', true, 200, 'terminated'],
    // ... y el 2 ya es ayer: la baja es en el acto aunque no se pida.
    'Canarias a las 00:30 del dia 3, cese el 2' => ['2026-10-02 23:30:00', 'Atlantic/Canary', '2026-10-02', false, 200, 'terminated'],
    // 21:30 UTC del dia 2 son las 23:30 del dia 2 en Madrid: el 3 es mañana.
    'Madrid a las 23:30 del dia 2, aplicar ya el 3' => ['2026-10-02 21:30:00', 'Europe/Madrid', '2026-10-03', true, 422, 'active'],
    // ... y el 2 todavia es hoy: sin aplicar ya, se programa.
    'Madrid a las 23:30 del dia 2, cese el 2' => ['2026-10-02 21:30:00', 'Europe/Madrid', '2026-10-02', false, 200, 'active'],
    'cese el dia del alta' => ['2026-10-02 10:00:00', 'Europe/Madrid', '2026-01-01', false, 200, 'terminated'],
    'cese el dia anterior al alta' => ['2026-10-02 10:00:00', 'Europe/Madrid', '2025-12-31', false, 422, 'active'],
])->group('RN-14', 'RF-GP-03');

it('a una persona que aun no ha empezado la da de baja sin efectos en su fecha de alta, y programa una posterior', function (): void {
    FrozenTime::at('2026-10-02 10:00:00');
    $persona = personaParaLaBajaConFecha();
    DB::table('employees')->where('uuid', $persona)->update(['hired_at' => '2026-10-15']);
    $token = tokenDeLaBajaConFecha();

    Api::as($token)
        ->post('/api/v1/employees/'.$persona.'/offboard', ['terminated_at' => '2026-10-02'])
        ->assertValidResponse(422)
        ->assertJsonPath('errors.terminated_at.0', 'La fecha de cese (2026-10-02) es anterior a la de alta (2026-10-15).');

    Api::as($token)
        ->post('/api/v1/employees/'.$persona.'/offboard', ['terminated_at' => '2026-10-20'])
        ->assertValidResponse(200)
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('offboarding.state', 'scheduled');

    Api::as($token)
        ->post('/api/v1/employees/'.$persona.'/offboard', ['terminated_at' => '2026-10-15'])
        ->assertValidResponse(200)
        ->assertJsonPath('status', 'terminated')
        ->assertJsonPath('terminated_at', '2026-10-15')
        ->assertJsonPath('offboarding.state', 'effective');
})->group('RN-14', 'RF-GP-03');

it('sin centro configurado no da de baja a nadie y no cae a UTC', function (): void {
    // R-4: sin la zona del centro no hay «hoy» que resolver. Un estado de la
    // instalacion, no un error de la peticion: 409.
    FrozenTime::at('2026-10-02 10:00:00');

    Api::as(tokenDeLaBajaConFecha())
        ->post('/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90/offboard', ['terminated_at' => '2026-10-02'])
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:conflict');

    expect(DB::table('audit_log')->whereIn('action', ['employee.offboarded', 'employee.offboarding_scheduled'])->count())->toBe(0);
})->group('RN-14', 'RF-GP-03');
