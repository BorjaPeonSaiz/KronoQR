<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Http\AuthorizationMatrix;
use Tests\Support\Identity\Credentials;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **EL INSTANTE DE LA BAJA EFECTIVA EN EL LISTADO Y LA FICHA** (RN-14, RN-27,
 * ADR-062 §1 y §4; `EmployeeOffboarding.offboarded_at`).
 *
 * El panel avisa de que «la baja fue hoy» antes de readmitir a alguien. Sin el
 * instante en el listado tenia que pedir la ficha de cada persona del lote. En
 * un departamento de recepcion hay una persona de baja desde esta mañana, otra
 * con la baja programada y otra en alta. `rrhh` y `admin` reciben el instante;
 * el responsable, la baja sin el (`ScopedEmployeeOffboarding`).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    config()->set('identity.two_factor.required_roles', []);
});

/**
 * @return array{department: int, responsable: string, effective: string, scheduled: string, active: string}
 */
function recepcionConUnaBajaDeHoy(): array
{
    FrozenTime::at('2026-10-30 07:12:30');
    $site = WorkforceFixtures::site();
    $recepcion = WorkforceFixtures::department($site, 'Recepcion');
    $responsable = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    Department::query()->whereKey($recepcion)->update(['manager_user_id' => $responsable->id]);

    $baja = WorkforceFixtures::employee($site, $recepcion, lastName: 'Aaa');
    $programada = WorkforceFixtures::employee($site, $recepcion, lastName: 'Bbb');
    $alta = WorkforceFixtures::employee($site, $recepcion, lastName: 'Ccc');
    $id = DB::table('employees')->where('uuid', $baja)->value('id');
    Credentials::issueFor(is_numeric($id) ? (int) $id : 0);

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($token)
        ->post('/api/v1/employees/'.$baja.'/offboard', ['terminated_at' => '2026-10-30', 'apply_now' => true])
        ->assertValidResponse(200)
        ->assertJsonPath('offboarding.state', 'effective')
        // La respuesta de la baja ya lo lleva.
        ->assertJsonPath('offboarding.offboarded_at', '2026-10-30T07:12:30.000000Z');

    Api::as($token)
        ->post('/api/v1/employees/'.$programada.'/offboard', ['terminated_at' => '2026-11-15'])
        ->assertValidResponse(200)
        ->assertJsonPath('offboarding.offboarded_at', null);

    return [
        'department' => $recepcion,
        'responsable' => ManagementUsers::tokenFor($responsable),
        'effective' => $baja,
        'scheduled' => $programada,
        'active' => $alta,
    ];
}

/**
 * La baja de cada persona del listado, indexada por `uuid`.
 *
 * @return array<string, mixed>
 */
function bajasDelListado(string $token, int $department): array
{
    $respuesta = Api::as($token)
        ->get('/api/v1/employees', ['department_id' => $department])
        ->assertValidResponse(200);

    /** @var list<array{uuid: string, offboarding: mixed}> $data */
    $data = $respuesta->json('data');

    return array_column($data, 'offboarding', 'uuid');
}

it('da a rrhh y admin el instante de la baja efectiva en el listado y en la ficha', function (UserRole $rol): void {
    $recepcion = recepcionConUnaBajaDeHoy();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole($rol));

    expect(bajasDelListado($token, $recepcion['department']))->toEqual([
        $recepcion['effective'] => ['state' => 'effective', 'terminated_at' => '2026-10-30', 'offboarded_at' => '2026-10-30T07:12:30.000000Z'],
        $recepcion['scheduled'] => ['state' => 'scheduled', 'terminated_at' => '2026-11-15', 'offboarded_at' => null],
        $recepcion['active'] => null,
    ]);

    Api::as($token)
        ->get('/api/v1/employees/'.$recepcion['effective'])
        ->assertValidResponse(200)
        ->assertJsonPath('offboarding.offboarded_at', '2026-10-30T07:12:30.000000Z')
        ->assertJsonPath('employment_periods.0.offboarded_at', '2026-10-30T07:12:30.000000Z');
})->with([
    'rrhh' => [UserRole::RRHH],
    'admin' => [UserRole::ADMIN],
])->group('RN-14', 'RN-27', 'RF-GP-03', 'RQ-06');

it('no da el instante de la baja al responsable, ni en el listado ni en la ficha', function (): void {
    $recepcion = recepcionConUnaBajaDeHoy();

    expect(bajasDelListado($recepcion['responsable'], $recepcion['department']))->toEqual([
        $recepcion['effective'] => ['state' => 'effective', 'terminated_at' => '2026-10-30'],
        $recepcion['scheduled'] => ['state' => 'scheduled', 'terminated_at' => '2026-11-15'],
        $recepcion['active'] => null,
    ]);

    Api::as($recepcion['responsable'])
        ->get('/api/v1/employees/'.$recepcion['effective'])
        ->assertValidResponse(200)
        ->assertJsonPath('offboarding.state', 'effective')
        ->assertJsonMissingPath('offboarding.offboarded_at');
})->group('RN-14', 'RF-ID-03', 'RS-05');

it('deniega el listado, y con el el instante de la baja, a cada actor que la matriz no autoriza', function (string $actor): void {
    $recepcion = recepcionConUnaBajaDeHoy();
    $token = AuthorizationMatrix::tokenFor($actor, ['employee' => $recepcion['active'], 'responsable' => $recepcion['responsable']]);

    $cuerpo = (string) Api::as($token)
        ->get('/api/v1/employees', ['department_id' => $recepcion['department']])
        ->assertStatus(403)
        ->assertJsonPath('type', 'urn:kronoqr:problem:forbidden')
        ->getContent();

    expect($cuerpo)->not->toContain('offboarded_at')
        ->and($cuerpo)->not->toContain($recepcion['effective']);
})->with(fn (): array => array_values(array_diff(
    AuthorizationMatrix::actors(),
    AuthorizationMatrix::routes()['GET api/v1/employees'],
)))->group('RN-14', 'RS-05', 'RQ-07');

it('lee el instante de las bajas de toda la pagina en una sola consulta, sin N+1', function (): void {
    $recepcion = recepcionConUnaBajaDeHoy();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    /** @return list<string> */
    $consultas = static function () use ($token, $recepcion): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        bajasDelListado($token, $recepcion['department']);
        $log = array_map(static fn (array $entrada): string => (string) $entrada['query'], DB::getQueryLog());
        DB::disableQueryLog();

        return $log;
    };

    // Calienta lo que se resuelve una vez por proceso o por token.
    $consultas();
    $una = $consultas();

    // Dos bajas efectivas mas en la misma pagina.
    $site = WorkforceFixtures::site();

    foreach (['Ddd', 'Eee'] as $apellido) {
        WorkforceFixtures::employee($site, $recepcion['department'], status: 'terminated', lastName: $apellido, hiredAt: '2025-01-01', terminatedAt: '2026-06-30');
    }

    $tres = $consultas();

    $dePeriodos = array_values(array_filter($tres, static fn (string $sql): bool => str_contains($sql, 'employment_periods')));

    expect($dePeriodos)->toHaveCount(1)
        ->and(\count($tres))->toBe(\count($una));
})->group('RN-14', 'RN-27');
