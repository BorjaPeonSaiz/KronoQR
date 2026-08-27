<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Regla dura 18 y RQ-07: los tres endpoints de correccion con su prueba de
 * autorizacion negativa **por cada rol no autorizado**, sin excepciones.
 *
 * Estos endpoints son, junto con `/scan`, los unicos del producto que **escriben
 * registro horario**. La diferencia es que aqui quien escribe lo hace a nombre de
 * otra persona y con su firma: quien pueda llamarlos puede cambiar las horas —y
 * por tanto la nomina— de cualquiera. Por eso se prueba por parejas y no con un
 * caso representativo.
 *
 * Se comprueban los DOS controles del doc 02 §7.3, y por separado:
 *
 *   - **El ambito del token** (`attendance:correct`), que verifica el middleware
 *     `ability`. Es lo que deja fuera al `auditor` —tiene `attendance:read`, que
 *     es mirar— y a cualquier token de quiosco.
 *   - **La policy**, que verifica el ROL. Es lo que deja fuera a una cuenta que
 *     tuviera el ambito sin el rol.
 *
 * Que los dos devuelvan 403 es correcto: desde fuera no se distingue por que se
 * ha denegado, y no hay motivo para decirlo.
 */

uses(RefreshDatabase::class);

/**
 * Un tramo real sobre el que intentar cada operacion, para que lo que falle sea
 * la autorizacion y no la busqueda del recurso.
 *
 * @return array{site: int, employee: string, entry: string}
 */
function tramoParaAutorizacion(): array
{
    $site = WorkforceFixtures::site('Hotel de permisos');
    $employee = WorkforceFixtures::employee($site);

    $jornada = WorkDay::start($employee, $site, WorkDate::fromIsoDate('2026-03-14', Instants::madrid()));
    $entrada = $jornada->clockIn(Str::uuid7()->toString(), Instants::utc('2026-03-14 06:00'), ScanOrigin::QR_KIOSK);
    app(WorkDayRepository::class)->save($jornada);

    return ['site' => $site, 'employee' => $employee, 'entry' => $entrada->uuid()];
}

/**
 * Las tres llamadas, con cuerpos validos.
 *
 * @return list<array{0: string, 1: string, 2: array<string, string>}>
 */
function operacionesDeCorreccion(string $employee, string $entry): array
{
    return [
        ['POST', '/api/v1/shift-entries', [
            'employee_uuid' => $employee,
            'work_date' => '2026-03-15',
            'clocked_in_at' => '2026-03-15T06:00:00Z',
            'clocked_out_at' => '2026-03-15T14:00:00Z',
            'reason_code' => 'ALTA_RETROACTIVA',
        ]],
        ['PATCH', '/api/v1/shift-entries/'.$entry, [
            'clocked_out_at' => '2026-03-14T14:00:00Z',
            'reason_code' => 'OLVIDO_FICHAJE_SALIDA',
        ]],
        ['POST', '/api/v1/shift-entries/'.$entry.'/void', [
            'reason_code' => 'ERROR_DE_ESCANEO_DUPLICADO',
        ]],
    ];
}

it('no deja corregir sin token', function (): void {
    // La mitad de la regla dura 18 que mas se olvida: comprobar tambien que sin
    // credenciales no se entra.
    ['employee' => $employee, 'entry' => $entry] = tramoParaAutorizacion();

    foreach (operacionesDeCorreccion($employee, $entry) as [$metodo, $ruta, $cuerpo]) {
        Api::guest()->call($metodo, $ruta, $cuerpo)->assertStatus(401);
    }

    expect(DB::table('shift_corrections')->count())->toBe(0);
})->group('RF-ID-03', 'RF-PA-04', 'RS-04');

it('no deja corregir a un empleado ni a un quiosco', function (string $role): void {
    // Un empleado no corrige nada, ni siquiera lo suyo: si pudiera, el registro
    // horario dejaria de ser un registro y pasaria a ser una declaracion. Y un
    // quiosco menos todavia — su token vive colgado de una pared (RS-04).
    ['employee' => $employee, 'entry' => $entry] = tramoParaAutorizacion();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::from($role)));

    foreach (operacionesDeCorreccion($employee, $entry) as [$metodo, $ruta, $cuerpo]) {
        Api::as($token)->call($metodo, $ruta, $cuerpo)->assertStatus(403);
    }

    // Y no ha quedado rastro de escritura: un 403 que ya hubiera tocado la tabla
    // seria peor que un 200.
    expect(DB::table('shift_corrections')->count())->toBe(0)
        ->and(DB::table('shift_entries')->where('status', 'voided')->count())->toBe(0);
})->with([
    'empleado' => UserRole::EMPLEADO->value,
    'quiosco' => UserRole::KIOSK->value,
])->group('RF-ID-03', 'RF-PA-04');

it('no deja corregir a un auditor, que solo puede mirar', function (): void {
    // Es el caso que justifica que `attendance:correct` sea un ambito propio y no
    // parte de `attendance:read`: auditar es mirar. Con un solo ambito, quien
    // puede consultar la presencia podria cambiar las horas de cualquiera.
    ['employee' => $employee, 'entry' => $entry] = tramoParaAutorizacion();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::AUDITOR));

    foreach (operacionesDeCorreccion($employee, $entry) as [$metodo, $ruta, $cuerpo]) {
        Api::as($token)->call($metodo, $ruta, $cuerpo)->assertStatus(403);
    }
})->group('RF-ID-03', 'RF-PA-04', 'RS-04');

it('no deja corregir a un responsable de departamento en esta fase', function (): void {
    // RF-ID-03 completo —el alcance por departamento— es de la tarea 2.1. Hasta
    // entonces, darle acceso seria darle el registro horario de TODA la
    // instalacion, que es justo lo que ese requisito viene a impedir.
    ['employee' => $employee, 'entry' => $entry] = tramoParaAutorizacion();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO));

    foreach (operacionesDeCorreccion($employee, $entry) as [$metodo, $ruta, $cuerpo]) {
        Api::as($token)->call($metodo, $ruta, $cuerpo)->assertStatus(403);
    }
})->group('RF-ID-03', 'RF-PA-04');

it('deniega el ambito aunque el rol sea el correcto', function (): void {
    // La mitad de la autorizacion que no es la policy (doc 02 §7.3). Un token de
    // RRHH emitido SIN `attendance:correct` —por ejemplo el de una sesion
    // antigua, o uno recortado a proposito— no alcanza estos endpoints aunque su
    // portadora pueda corregir.
    ['employee' => $employee, 'entry' => $entry] = tramoParaAutorizacion();

    $usuaria = ManagementUsers::withRole(UserRole::RRHH);
    $token = $usuaria->createToken('Sesion sin el ambito', [
        TokenAbility::ATTENDANCE_READ->value,
    ])->plainTextToken;

    foreach (operacionesDeCorreccion($employee, $entry) as [$metodo, $ruta, $cuerpo]) {
        Api::as($token)->call($metodo, $ruta, $cuerpo)->assertStatus(403);
    }
})->group('RS-04', 'RF-PA-04');

it('deja a admin y a rrhh corregir, que son los dos roles de esta fase', function (string $role): void {
    // El contrapunto de las pruebas negativas: sin esto, una policy que
    // denegara a todo el mundo pasaria las cinco de arriba.
    ['employee' => $employee, 'entry' => $entry] = tramoParaAutorizacion();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::from($role)));

    Api::as($token)
        ->post('/api/v1/shift-entries/'.$entry.'/void', ['reason_code' => 'ERROR_DE_ESCANEO_DUPLICADO'])
        ->assertStatus(200);
})->with([
    'admin' => UserRole::ADMIN->value,
    'rrhh' => UserRole::RRHH->value,
])->group('RF-ID-03', 'RF-PA-04');
