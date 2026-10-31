<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Http\AuthorizationMatrix;
use Tests\Support\Identity\Credentials;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **EL HISTORICO DE LA FECHA DE CESE EN LA FICHA** (RN-27, RN-13, RL-04,
 * regla dura 5; ADR-062 §1, §7 y §10).
 *
 * «Omar» causo baja con fecha de cese el 15 de octubre. RRHH la corrigio al 18
 * y, al dia siguiente, la administracion al 17. Despues volvio el 25. La ficha
 * de `rrhh+` tiene que seguir enseñando, despues de recargar, las dos fechas
 * anteriores con su autor, su momento y su motivo, y el instante en que la baja
 * se hizo efectiva. El responsable de su departamento no recibe los periodos.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    config()->set('identity.two_factor.required_roles', []);
});

const EMPLOYMENT_HISTORY_FIRST_REASON = 'Trabajo hasta el 18 segun el cuadrante firmado';

const EMPLOYMENT_HISTORY_SECOND_REASON = 'El cuadrante bueno es el del 17, el otro era un borrador';

/**
 * Omar, de baja desde el 15, con dos correcciones de autores distintos y
 * readmitido el 25.
 *
 * @return array{employee: string, department: int, firstPeriod: string, rrhh: array{uuid: string, name: string}, admin: array{uuid: string, name: string}}
 */
function omarConElCeseCorregidoDosVeces(): array
{
    FrozenTime::at('2026-10-30 09:00:00');
    $escenario = AttendanceFixtures::scenario();
    Credentials::issueFor(AttendanceFixtures::employeeIdOf($escenario['employee']));

    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    $admin = ManagementUsers::withRole(UserRole::ADMIN);
    $tokenRrhh = ManagementUsers::tokenFor($rrhh);
    $tokenAdmin = ManagementUsers::tokenFor($admin);

    Api::as($tokenRrhh)
        ->post('/api/v1/employees/'.$escenario['employee'].'/offboard', ['terminated_at' => '2026-10-15'])
        ->assertValidResponse(200)
        ->assertJsonPath('status', 'terminated');

    /** @var string $periodo */
    $periodo = DB::table('employment_periods')
        ->where('employee_id', AttendanceFixtures::employeeIdOf($escenario['employee']))
        ->value('uuid');
    $ruta = '/api/v1/employees/'.$escenario['employee'].'/employment-periods/'.$periodo.'/termination-corrections';

    FrozenTime::at('2026-10-30 10:15:00');
    Api::as($tokenRrhh)
        ->post($ruta, ['terminated_at' => '2026-10-18', 'reason' => EMPLOYMENT_HISTORY_FIRST_REASON])
        ->assertValidResponse(201);

    FrozenTime::at('2026-10-31 08:30:00');
    Api::as($tokenAdmin)
        ->post($ruta, ['terminated_at' => '2026-10-17', 'reason' => EMPLOYMENT_HISTORY_SECOND_REASON])
        ->assertValidResponse(201);

    Api::as($tokenRrhh)
        ->post('/api/v1/employees/'.$escenario['employee'].'/rehire', ['hired_at' => '2026-10-25'])
        ->assertValidResponse(200);

    return [
        'employee' => $escenario['employee'],
        'department' => $escenario['department'],
        'firstPeriod' => $periodo,
        'rrhh' => ['uuid' => $rrhh->uuid, 'name' => $rrhh->name],
        'admin' => ['uuid' => $admin->uuid, 'name' => $admin->name],
    ];
}

it('enseña a rrhh y admin cada periodo con su baja efectiva y las correcciones del cese en orden', function (UserRole $rol): void {
    $omar = omarConElCeseCorregidoDosVeces();

    $ficha = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole($rol)))
        ->get('/api/v1/employees/'.$omar['employee'])
        ->assertValidResponse(200)
        ->assertJsonCount(2, 'employment_periods');

    $ficha->assertJsonPath('employment_periods.0', [
        'uuid' => $omar['firstPeriod'],
        'hired_at' => '2026-01-01',
        'terminated_at' => '2026-10-17',
        'state' => 'terminated',
        // El instante de la baja efectiva, que no cambia al corregir la fecha.
        'offboarded_at' => '2026-10-30T09:00:00.000000Z',
        'corrections' => [
            [
                'id' => DB::table('employment_period_corrections')->where('terminated_on', '2026-10-18')->value('id'),
                'previous_terminated_at' => '2026-10-15',
                'terminated_at' => '2026-10-18',
                'corrected_at' => '2026-10-30T10:15:00.000000Z',
                'corrected_by' => $omar['rrhh'],
                'reason' => EMPLOYMENT_HISTORY_FIRST_REASON,
            ],
            [
                'id' => DB::table('employment_period_corrections')->where('terminated_on', '2026-10-17')->value('id'),
                'previous_terminated_at' => '2026-10-18',
                'terminated_at' => '2026-10-17',
                'corrected_at' => '2026-10-31T08:30:00.000000Z',
                'corrected_by' => $omar['admin'],
                'reason' => EMPLOYMENT_HISTORY_SECOND_REASON,
            ],
        ],
    ])
        // El periodo en curso: sin baja y sin correcciones.
        ->assertJsonPath('employment_periods.1.hired_at', '2026-10-25')
        ->assertJsonPath('employment_periods.1.state', 'active')
        ->assertJsonPath('employment_periods.1.offboarded_at', null)
        ->assertJsonPath('employment_periods.1.corrections', []);

    // Del autor, el uuid y el nombre; nunca el correo (`CorrectionAuthor`).
    expect($ficha->getContent())->not->toContain('@kronoqr.test');
})->with([
    'rrhh' => [UserRole::RRHH],
    'admin' => [UserRole::ADMIN],
])->group('RN-27', 'RN-13', 'RL-04', 'RF-GP-01');

it('no da los periodos ni sus correcciones al responsable del departamento', function (): void {
    $omar = omarConElCeseCorregidoDosVeces();
    $responsable = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    Department::query()->whereKey($omar['department'])->update(['manager_user_id' => $responsable->id]);

    $ficha = Api::as(ManagementUsers::tokenFor($responsable))
        ->get('/api/v1/employees/'.$omar['employee'])
        ->assertValidResponse(200)
        ->assertJsonPath('uuid', $omar['employee'])
        ->assertJsonMissingPath('employment_periods');

    $cuerpo = (string) $ficha->getContent();

    expect($cuerpo)->not->toContain(EMPLOYMENT_HISTORY_FIRST_REASON)
        ->and($cuerpo)->not->toContain('offboarded_at')
        ->and($cuerpo)->not->toContain('corrections');
})->group('RN-27', 'RF-ID-03', 'RS-05');

it('deniega la ficha, y con ella el historico, a cada actor que la matriz no autoriza', function (string $actor): void {
    $omar = omarConElCeseCorregidoDosVeces();
    $token = AuthorizationMatrix::tokenFor($actor, ['employee' => $omar['employee'], 'responsable' => '']);

    $respuesta = Api::as($token)
        ->get('/api/v1/employees/'.$omar['employee'])
        ->assertStatus(403)
        ->assertJsonPath('type', 'urn:kronoqr:problem:forbidden');

    $cuerpo = (string) $respuesta->getContent();

    // Una denegacion por rol o por ambito de token no deja `access.denied`:
    // solo la de alcance (doc 02 §9.4, doc 07 A-16).
    expect($cuerpo)->not->toContain(EMPLOYMENT_HISTORY_FIRST_REASON)
        ->and($cuerpo)->not->toContain('2026-10-17')
        ->and(DB::table('audit_log')->where('action', AuditAction::AccessDenied->value)->count())->toBe(0);
})->with(fn (): array => array_values(array_diff(
    AuthorizationMatrix::actors(),
    AuthorizationMatrix::routes()['GET api/v1/employees/{uuid}'],
)))->group('RN-27', 'RS-05', 'RQ-07');

it('deniega el historico al responsable de otro departamento y lo deja en auditoria', function (): void {
    $omar = omarConElCeseCorregidoDosVeces();
    $jefeDeCocina = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    $cocina = WorkforceFixtures::department(WorkforceFixtures::onlySiteId(), 'Cocina');
    Department::query()->whereKey($cocina)->update(['manager_user_id' => $jefeDeCocina->id]);

    $respuesta = Api::as(ManagementUsers::tokenFor($jefeDeCocina))
        ->get('/api/v1/employees/'.$omar['employee'])
        ->assertStatus(403)
        ->assertJsonPath('type', 'urn:kronoqr:problem:forbidden');

    $asiento = DB::table('audit_log')->where('action', AuditAction::AccessDenied->value)->sole();

    expect((string) $respuesta->getContent())->not->toContain(EMPLOYMENT_HISTORY_FIRST_REASON)
        ->and((string) json_encode($asiento))->toContain($omar['employee'])
        ->and((string) json_encode($asiento))->not->toContain(EMPLOYMENT_HISTORY_FIRST_REASON);
})->group('RN-27', 'RF-ID-03', 'RS-05', 'RQ-07');

it('una baja programada no tiene offboarded_at ni correcciones', function (): void {
    FrozenTime::at('2026-10-30 09:00:00');
    $escenario = AttendanceFixtures::scenario();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($token)
        ->post('/api/v1/employees/'.$escenario['employee'].'/offboard', ['terminated_at' => '2026-11-15'])
        ->assertValidResponse(200)
        ->assertJsonPath('offboarding.state', 'scheduled');

    Api::as($token)
        ->get('/api/v1/employees/'.$escenario['employee'])
        ->assertValidResponse(200)
        ->assertJsonCount(1, 'employment_periods')
        ->assertJsonPath('employment_periods.0.state', 'scheduled')
        ->assertJsonPath('employment_periods.0.terminated_at', '2026-11-15')
        ->assertJsonPath('employment_periods.0.offboarded_at', null)
        ->assertJsonPath('employment_periods.0.corrections', []);
})->group('RN-27', 'RN-14');

it('en una ficha minimizada conserva fechas, autor y momento, y el motivo es el texto fijo', function (): void {
    FrozenTime::at('2026-10-30 09:00:00');
    $persona = WorkforceFixtures::employee(WorkforceFixtures::site(), status: 'terminated', hiredAt: '2010-01-01', terminatedAt: '2012-12-31');
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    $token = ManagementUsers::tokenFor($rrhh);

    /** @var string $periodo */
    $periodo = DB::table('employment_periods')->where('employee_id', AttendanceFixtures::employeeIdOf($persona))->value('uuid');

    Api::as($token)
        ->post('/api/v1/employees/'.$persona.'/employment-periods/'.$periodo.'/termination-corrections', [
            'terminated_at' => '2012-12-20',
            'reason' => 'Lucia Ferrer dejo de venir el 20, lo dice su parte firmado',
        ])
        ->assertValidResponse(201);

    /** @var object{today: string} $corte */
    $corte = DB::selectOne("SELECT ((now() AT TIME ZONE 'UTC')::date - interval '4 years')::date::text AS today");
    DB::select(
        'SELECT * FROM public.personnel_record_anonymize_batch(?::date, 10, ARRAY(SELECT c.id FROM public.personnel_record_retention_candidates(?::date) c))',
        [$corte->today, $corte->today],
    );

    $migracion = require database_path('migrations/2026_10_30_110000_minimise_termination_correction_reasons.php');

    $ficha = Api::as($token)
        ->get('/api/v1/employees/'.$persona)
        ->assertValidResponse(200)
        ->assertJsonPath('employment_periods.0.terminated_at', '2012-12-20')
        ->assertJsonPath('employment_periods.0.corrections.0.previous_terminated_at', '2012-12-31')
        ->assertJsonPath('employment_periods.0.corrections.0.terminated_at', '2012-12-20')
        ->assertJsonPath('employment_periods.0.corrections.0.corrected_at', '2026-10-30T09:00:00.000000Z')
        ->assertJsonPath('employment_periods.0.corrections.0.corrected_by', ['uuid' => $rrhh->uuid, 'name' => $rrhh->name])
        ->assertJsonPath('employment_periods.0.corrections.0.reason', $migracion::CORRECTION_REASON);

    expect($ficha->json('anonymized_at'))->not->toBeNull()
        ->and($ficha->getContent())->not->toContain('Lucia')
        ->and($ficha->getContent())->not->toContain('parte firmado');
})->group('RN-27', 'RN-13', 'RL-11');

it('lee los periodos y sus correcciones en una sola consulta, sin N+1', function (): void {
    $omar = omarConElCeseCorregidoDosVeces();
    $sinHistoria = WorkforceFixtures::employee(WorkforceFixtures::site(), $omar['department']);
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    /** @return list<string> */
    $consultas = static function (string $empleado) use ($token): array {
        DB::flushQueryLog();
        DB::enableQueryLog();

        Api::as($token)->get('/api/v1/employees/'.$empleado)->assertOk();

        $log = array_map(static fn (array $entrada): string => (string) $entrada['query'], DB::getQueryLog());
        DB::disableQueryLog();

        return $log;
    };

    // La primera vuelta calienta lo que se resuelve una vez por proceso o por
    // token (instalacion, licencia, ultimo uso del token).
    $consultas($sinHistoria);

    $una = $consultas($sinHistoria);
    $omarConsultas = $consultas($omar['employee']);

    $deCorrecciones = array_values(array_filter(
        $omarConsultas,
        static fn (string $sql): bool => str_contains($sql, 'employment_period_corrections'),
    ));

    // Dos periodos y dos correcciones cuestan lo mismo que un periodo sin
    // ninguna: una sola sentencia lee los periodos con sus correcciones.
    expect($deCorrecciones)->toHaveCount(1)
        ->and($deCorrecciones[0])->toContain('employment_periods')
        ->and(\count($omarConsultas))->toBe(\count($una));
})->group('RN-27');
