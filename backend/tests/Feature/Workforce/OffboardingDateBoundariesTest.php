<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\Credentials;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LAS FRONTERAS DEL «HOY» DE LA BAJA** (RN-14, RF-GP-03, ADR-046 §6 punto 6;
 * hallazgos R4-SC-01 y R3-BE-01).
 *
 * «Hoy» es la fecha civil del centro en el instante en que llega la baja. Se
 * prueba donde una implementacion con la fecha UTC, o con la zona equivocada, se
 * equivoca de dia: a las 23:30 y a las 00:30 de la hora local, en Canarias y en
 * Madrid, un dia normal y los dos dias de cambio de hora de 2026 (29 de marzo y
 * 25 de octubre, a la 01:00 UTC en las dos zonas).
 *
 * Los instantes van en UTC y escritos a mano, con su hora local al lado: un
 * valor limite calculado en la prueba repetiria el mismo error que el codigo.
 * `OffboardingDateRuleTest` cubre ya Canarias a las 00:30 con cese el dia 3 y
 * Madrid a las 23:30 con cese el dia 3; aqui estan las demas esquinas.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

function tokenDeLasFronterasDeLaBaja(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
}

/**
 * Una persona en alta desde el 2026-01-01, con una tarjeta activa, en un centro
 * de la zona indicada.
 */
function personaEnLaFronteraDeLaBaja(string $timezone): string
{
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site('Hotel de la frontera', $timezone));
    $id = idDeLaPersonaEnLaFrontera($uuid);
    Credentials::issueFor($id);
    // La tarjeta, emitida el dia siguiente al alta: el reloj de marzo va por
    // detras de la emision de serie de `Credentials` (agosto), y una revocacion
    // anterior a la emision la rechaza `credentials_chk_*`.
    DB::table('credentials')->where('employee_id', $id)->update([
        'issued_at' => '2026-01-02 08:00:00+00',
        'printed_at' => '2026-01-02 08:00:00+00',
    ]);

    return $uuid;
}

function idDeLaPersonaEnLaFrontera(string $uuid): int
{
    /** @var int $id */
    $id = DB::table('employees')->where('uuid', $uuid)->value('id');

    return $id;
}

/**
 * Todo lo que una baja rechazada no puede haber tocado: la ficha, sus tarjetas,
 * el registro de auditoria y las sesiones de su portal.
 *
 * @return array{employee: array<string, mixed>, credentials: list<array<string, mixed>>, audit_entries: int, portal_tokens: list<array<string, mixed>>}
 */
function loQueLaBajaRechazadaNoToca(string $uuid): array
{
    $id = idDeLaPersonaEnLaFrontera($uuid);

    /** @var object $employee */
    $employee = DB::table('employees')->where('id', $id)->first();

    return [
        'employee' => (array) $employee,
        'credentials' => array_values(array_map(
            static fn (object $row): array => (array) $row,
            DB::table('credentials')->where('employee_id', $id)->orderBy('id')->get()->all(),
        )),
        'audit_entries' => DB::table('audit_log')->count(),
        'portal_tokens' => array_values(array_map(
            static fn (object $row): array => (array) $row,
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $id)
                ->where('tokenable_type', '!=', User::class)
                ->orderBy('id')
                ->get()
                ->all(),
        )),
    ];
}

it('admite el cese en el dia civil del centro en la frontera de la medianoche', function (string $ahora, string $zona, string $cese): void {
    FrozenTime::at($ahora);
    $persona = personaEnLaFronteraDeLaBaja($zona);

    Api::as(tokenDeLasFronterasDeLaBaja())
        ->post('/api/v1/employees/'.$persona.'/offboard', ['terminated_at' => $cese])
        ->assertValidResponse(200)
        ->assertJsonPath('terminated_at', $cese);

    expect(DB::table('employees')->where('uuid', $persona)->first(['status', 'terminated_at']))
        ->toEqual((object) ['status' => 'terminated', 'terminated_at' => $cese]);
})->with([
    'Canarias, 23:30 del 2 de octubre (22:30 UTC), cese el 2' => ['2026-10-02 22:30:00', 'Atlantic/Canary', '2026-10-02'],
    'Madrid, 00:30 del 3 de octubre (22:30 UTC del 2), cese el 3' => ['2026-10-02 22:30:00', 'Europe/Madrid', '2026-10-03'],
    'Madrid, 23:30 del 2 de octubre (21:30 UTC), cese el 2' => ['2026-10-02 21:30:00', 'Europe/Madrid', '2026-10-02'],
    'Madrid, 00:30 del 29 de marzo, aun en invierno (23:30 UTC del 28), cese el 29' => ['2026-03-28 23:30:00', 'Europe/Madrid', '2026-03-29'],
    'Madrid, 23:30 del 29 de marzo, ya en verano (21:30 UTC), cese el 29' => ['2026-03-29 21:30:00', 'Europe/Madrid', '2026-03-29'],
    'Canarias, 00:30 del 29 de marzo, aun en invierno (00:30 UTC), cese el 29' => ['2026-03-29 00:30:00', 'Atlantic/Canary', '2026-03-29'],
    'Canarias, 23:30 del 29 de marzo, ya en verano (22:30 UTC), cese el 29' => ['2026-03-29 22:30:00', 'Atlantic/Canary', '2026-03-29'],
    'Madrid, 00:30 del 25 de octubre, aun en verano (22:30 UTC del 24), cese el 25' => ['2026-10-24 22:30:00', 'Europe/Madrid', '2026-10-25'],
    'Madrid, 23:30 del 25 de octubre, ya en invierno (22:30 UTC), cese el 25' => ['2026-10-25 22:30:00', 'Europe/Madrid', '2026-10-25'],
    'Canarias, 00:30 del 25 de octubre, aun en verano (23:30 UTC del 24), cese el 25' => ['2026-10-24 23:30:00', 'Atlantic/Canary', '2026-10-25'],
    'Canarias, 23:30 del 25 de octubre, ya en invierno (23:30 UTC), cese el 25' => ['2026-10-25 23:30:00', 'Atlantic/Canary', '2026-10-25'],
])->group('RN-14', 'RF-GP-03');

it('rechaza con 422 el cese de mañana en la frontera de la medianoche y no toca ficha, tarjetas, auditoria ni portal', function (string $ahora, string $zona, string $cese): void {
    FrozenTime::at($ahora);
    $persona = personaEnLaFronteraDeLaBaja($zona);
    PortalLogins::open($persona);
    $antes = loQueLaBajaRechazadaNoToca($persona);

    Api::as(tokenDeLasFronterasDeLaBaja())
        ->post('/api/v1/employees/'.$persona.'/offboard', ['terminated_at' => $cese])
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed')
        ->assertJsonStructure(['errors' => ['terminated_at']]);

    expect(loQueLaBajaRechazadaNoToca($persona))->toBe($antes)
        ->and($antes['employee']['status'])->toBe('active')
        ->and($antes['credentials'])->toHaveCount(1)
        ->and($antes['portal_tokens'])->toHaveCount(1);
})->with([
    'Canarias, 23:30 del 2 de octubre (22:30 UTC), cese el 3' => ['2026-10-02 22:30:00', 'Atlantic/Canary', '2026-10-03'],
    'Canarias, 00:30 del 3 de octubre (23:30 UTC del 2), cese el 4' => ['2026-10-02 23:30:00', 'Atlantic/Canary', '2026-10-04'],
    'Madrid, 00:30 del 3 de octubre (22:30 UTC del 2), cese el 4' => ['2026-10-02 22:30:00', 'Europe/Madrid', '2026-10-04'],
    'Madrid, 00:30 del 29 de marzo, aun en invierno (23:30 UTC del 28), cese el 30' => ['2026-03-28 23:30:00', 'Europe/Madrid', '2026-03-30'],
    'Madrid, 23:30 del 29 de marzo, ya en verano (21:30 UTC), cese el 30' => ['2026-03-29 21:30:00', 'Europe/Madrid', '2026-03-30'],
    'Canarias, 00:30 del 29 de marzo, aun en invierno (00:30 UTC), cese el 30' => ['2026-03-29 00:30:00', 'Atlantic/Canary', '2026-03-30'],
    'Canarias, 23:30 del 29 de marzo, ya en verano (22:30 UTC), cese el 30' => ['2026-03-29 22:30:00', 'Atlantic/Canary', '2026-03-30'],
    'Madrid, 00:30 del 25 de octubre, aun en verano (22:30 UTC del 24), cese el 26' => ['2026-10-24 22:30:00', 'Europe/Madrid', '2026-10-26'],
    'Madrid, 23:30 del 25 de octubre, ya en invierno (22:30 UTC), cese el 26' => ['2026-10-25 22:30:00', 'Europe/Madrid', '2026-10-26'],
    'Canarias, 00:30 del 25 de octubre, aun en verano (23:30 UTC del 24), cese el 26' => ['2026-10-24 23:30:00', 'Atlantic/Canary', '2026-10-26'],
    'Canarias, 23:30 del 25 de octubre, ya en invierno (23:30 UTC), cese el 26' => ['2026-10-25 23:30:00', 'Atlantic/Canary', '2026-10-26'],
])->group('RN-14', 'RF-GP-03');
