<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Application\UseCase\ApplyEmployeeImport;
use App\Modules\Workforce\Application\UseCase\PlanEmployeeImport;
use App\Modules\Workforce\Domain\Exception\EmployeeAlreadyTerminated;
use App\Modules\Workforce\Domain\ValueObject\ImportOutcome;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\InstallationLocale;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\ImportFiles;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LA IMPORTACION NO MODIFICA A QUIEN ESTA DE BAJA: PROPONE SU READMISION**
 * (RF-GP-05, RN-14, RN-27, ADR-046 §5, ADR-062 §6).
 *
 * Hasta la 2.2.x la linea de una persona dada de baja se rechazaba con
 * `employee_terminated`. Desde la 2.3.0 se propone su readmision con la fecha de
 * alta de la linea, que tiene que ser posterior al cese: la reimportacion del
 * fichero de siempre, con la fecha de alta original, sigue sin tocar a nadie
 * (`rehire_not_after_termination`) y el resto del fichero se aplica.
 *
 * Si la baja confirma **entre** el informe y su aplicacion —dentro de la misma
 * peticion `apply`, que vuelve a planificar el fichero—, la aplicacion entera
 * falla con `EmployeeAlreadyTerminated` (`409`) y no escribe nada. Entre la
 * peticion de comprobar y la de aplicar, en cambio, la segunda planificacion ya
 * ve la baja y rechaza la linea.
 *
 * Como en `EmployeeImportTest`, la peticion multipart no se valida contra el
 * contrato —Spectator no sabe casarla— y la respuesta si.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    FrozenTime::at('2026-10-02 10:00:00');
});

/**
 * @return TestResponse<Response>
 */
function importarConBaja(string $token, UploadedFile $file, string $mode = 'validate', ?string $checksum = null, ?string $rehireDigest = null): TestResponse
{
    $fields = ['mode' => $mode];

    if ($checksum !== null) {
        $fields['confirm_checksum'] = $checksum;
    }

    if ($rehireDigest !== null) {
        $fields['confirm_rehire_digest'] = $rehireDigest;
    }

    return Api::as($token)->upload('/api/v1/employees/import', $fields, ['file' => $file]);
}

/**
 * El `rehire_digest` de la validacion, o `null` si no readmite a nadie.
 *
 * @param  TestResponse<Response>  $response
 */
function rehireDigestDe(TestResponse $response): ?string
{
    $digest = $response->json('rehire_digest');

    return is_string($digest) ? $digest : null;
}

/**
 * @param  TestResponse<Response>  $response
 */
function huellaDeLaImportacionConBaja(TestResponse $response): string
{
    $checksum = $response->json('file.sha256');

    return \is_string($checksum) ? $checksum : '';
}

/** Youssef ya importado, con otro apellido, y una persona nueva. */
function ficheroConUnaBaja(): string
{
    return "nombre,apellidos,dni,fecha_alta\n"
        ."Youssef,Amrani Nuevo,12345678Z,2026-01-15\n"
        ."Marta,Vidal,87654321X,2026-02-01\n";
}

/**
 * Da de alta a Youssef por importacion y devuelve su UUID.
 */
function youssefImportado(string $token): string
{
    $csv = "nombre,apellidos,dni,fecha_alta\nYoussef,Amrani,12345678Z,2026-01-15\n";
    $checksum = huellaDeLaImportacionConBaja(importarConBaja($token, ImportFiles::csv($csv)));

    importarConBaja($token, ImportFiles::csv($csv), 'apply', $checksum)->assertValidResponse(200);

    /** @var string $uuid */
    $uuid = DB::table('employees')->value('uuid');

    return $uuid;
}

it('rechaza la linea de una persona de baja con su fecha de alta original y aplica el resto', function (): void {
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    $youssef = youssefImportado($token);
    WorkforceFixtures::terminate($youssef);

    $comprobado = importarConBaja($token, ImportFiles::csv(ficheroConUnaBaja()))
        ->assertValidResponse(200)
        ->assertJsonPath('summary.reject', 1)
        ->assertJsonPath('summary.create', 1)
        ->assertJsonPath('rows.0.outcome', 'reject')
        ->assertJsonPath('rows.0.messages.0.code', 'rehire_not_after_termination')
        ->assertJsonPath('rows.0.messages.0.severity', 'error')
        ->assertJsonPath('rows.0.messages.0.detail', 'Esta persona está de baja y la fecha de alta de esta línea no '
            .'es posterior a su fecha de cese. Para darla de alta de nuevo, pon una fecha posterior al cese.');

    importarConBaja($token, ImportFiles::csv(ficheroConUnaBaja()), 'apply', huellaDeLaImportacionConBaja($comprobado))
        ->assertValidResponse(200)
        ->assertJsonPath('summary.create', 1)
        ->assertJsonPath('summary.reject', 1);

    expect(DB::table('employees')->count())->toBe(2)
        ->and(DB::table('employees')->where('uuid', $youssef)->first(['status', 'last_name']))
        ->toEqual((object) ['status' => 'terminated', 'last_name' => 'Amrani'])
        ->and(DB::table('employees')->where('last_name', 'Vidal')->value('status'))->toBe('active');
})->group('RF-GP-05', 'RN-14', 'RN-27');

it('lo dice en ingles a quien pide ingles', function (): void {
    // Con el ingles activo: de serie solo lo esta el español (bloque 26).
    InstallationLocale::set('es', ['es', 'en']);
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    WorkforceFixtures::terminate(youssefImportado($token));

    Api::as($token)
        ->withHeaders(['Accept-Language' => 'en'])
        ->upload('/api/v1/employees/import', ['mode' => 'validate'], ['file' => ImportFiles::csv(ficheroConUnaBaja())])
        ->assertValidResponse(200)
        ->assertJsonPath('rows.0.messages.0.detail', 'This person has left and the start date of this row is not after '
            .'their termination date. To rehire them, use a date after the termination.');
})->group('RF-GP-05', 'RN-14', 'RN-27');

it('si la baja confirma entre el informe y su aplicacion, la aplicacion entera falla y no escribe nada', function (): void {
    // ADR-046 §5, a nivel de caso de uso: el informe dice `update` y, cuando se
    // aplica, la modificacion lee la ficha con candado y la ve de baja. El lote
    // entero revierte: ni la persona nueva, ni un asiento.
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    $youssef = youssefImportado($token);

    /** @var array<string, list<string>> $aliases */
    $aliases = config()->array('workforce.import.column_aliases');
    $informe = app(PlanEmployeeImport::class)->handle(
        (string) ImportFiles::csv(ficheroConUnaBaja())->getRealPath(),
        500,
        $aliases,
    );

    expect($informe->countOf(ImportOutcome::UPDATE))->toBe(1);

    Api::as($token)
        ->post('/api/v1/employees/'.$youssef.'/offboard', ['terminated_at' => '2026-10-02', 'apply_now' => true])
        ->assertValidResponse(200);

    $empleados = DB::table('employees')->count();
    $asientos = DB::table('audit_log')->count();

    expect(fn () => app(ApplyEmployeeImport::class)->handle($informe))
        ->toThrow(EmployeeAlreadyTerminated::class);

    expect(DB::table('employees')->count())->toBe($empleados)
        ->and(DB::table('employees')->where('uuid', $youssef)->first(['status', 'last_name']))
        ->toEqual((object) ['status' => 'terminated', 'last_name' => 'Amrani'])
        ->and(DB::table('audit_log')->count())->toBe($asientos);
})->group('RF-GP-05', 'RN-14', 'RN-27');

it('si la baja llega entre la peticion de comprobar y la de aplicar, la aplicacion vuelve a comprobar y no la toca', function (): void {
    // `apply` vuelve a planificar el fichero antes de escribir y solo compara su
    // huella, no el informe: la linea de la persona que se dio de baja entre las
    // dos peticiones sale rechazada (su fecha de alta no es posterior al cese) y
    // el resto se aplica.
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    $youssef = youssefImportado($token);

    $comprobado = importarConBaja($token, ImportFiles::csv(ficheroConUnaBaja()))
        ->assertValidResponse(200)
        ->assertJsonPath('summary.update', 1);

    Api::as($token)
        ->post('/api/v1/employees/'.$youssef.'/offboard', ['terminated_at' => '2026-10-02', 'apply_now' => true])
        ->assertValidResponse(200);

    importarConBaja($token, ImportFiles::csv(ficheroConUnaBaja()), 'apply', huellaDeLaImportacionConBaja($comprobado))
        ->assertValidResponse(200)
        ->assertJsonPath('rows.0.messages.0.code', 'rehire_not_after_termination')
        ->assertJsonPath('summary.create', 1);

    expect(DB::table('employees')->where('uuid', $youssef)->first(['status', 'last_name']))
        ->toEqual((object) ['status' => 'terminated', 'last_name' => 'Amrani'])
        ->and(DB::table('audit_log')->where('action', 'employee.updated')->where('payload->employee_uuid', $youssef)->count())
        ->toBe(0);
})->group('RF-GP-05', 'RN-14', 'RN-27');

it('propone la readmision de una persona de baja con una fecha de alta posterior al cese y la aplica al confirmar', function (): void {
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    $youssef = youssefImportado($token);
    WorkforceFixtures::terminate($youssef);

    $fichero = "nombre,apellidos,dni,fecha_alta\nYoussef,Amrani,12345678Z,2026-09-01\n";

    $comprobado = importarConBaja($token, ImportFiles::csv($fichero))
        ->assertValidResponse(200)
        ->assertJsonPath('summary.rehire', 1)
        ->assertJsonPath('summary.reject', 0)
        ->assertJsonPath('rows.0.outcome', 'rehire')
        ->assertJsonPath('rows.0.employee_uuid', $youssef)
        ->assertJsonPath('rows.0.messages.0.code', 'rehire_proposed')
        ->assertJsonPath('rows.0.messages.0.severity', 'warning')
        ->assertJsonPath('rehire_digest', hash('sha256', $youssef));

    // Comprobar no escribe nada.
    expect(DB::table('employees')->where('uuid', $youssef)->value('status'))->toBe('terminated');

    importarConBaja($token, ImportFiles::csv($fichero), 'apply', huellaDeLaImportacionConBaja($comprobado), rehireDigestDe($comprobado))
        ->assertValidResponse(200)
        ->assertJsonPath('summary.rehire', 1);

    expect(DB::table('employees')->where('uuid', $youssef)->first(['status', 'hired_at', 'terminated_at']))
        ->toEqual((object) ['status' => 'active', 'hired_at' => '2026-09-01', 'terminated_at' => null])
        ->and(DB::table('audit_log')->where('action', 'employee.rehired')->where('payload->employee_uuid', $youssef)->value('payload'))
        ->toContain('"via_import": true')
        ->and(DB::table('audit_log')->where('action', 'employee.imported')->orderByDesc('id')->value('payload'))
        ->toContain('"rehired": 1');
})->group('RF-GP-05', 'RN-27');

it('sin fecha de alta la linea de una persona de baja da error y no se aplica', function (): void {
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    WorkforceFixtures::terminate(youssefImportado($token));

    importarConBaja($token, ImportFiles::csv("nombre,apellidos,dni,fecha_alta\nYoussef,Amrani,12345678Z,\n"))
        ->assertValidResponse(200)
        ->assertJsonPath('rows.0.outcome', 'reject')
        ->assertJsonCount(1, 'rows.0.messages')
        ->assertJsonPath('rows.0.messages.0.code', 'rehire_requires_hired_at');
})->group('RF-GP-05', 'RN-27');

/**
 * Da de alta a Youssef con correo por importacion, le da de baja y devuelve su
 * UUID.
 */
function youssefConCorreoDeBaja(string $token): string
{
    $csv = "nombre,apellidos,dni,correo,fecha_alta\nYoussef,Amrani,12345678Z,youssef@hotel.example,2026-01-15\n";
    $checksum = huellaDeLaImportacionConBaja(importarConBaja($token, ImportFiles::csv($csv)));

    importarConBaja($token, ImportFiles::csv($csv), 'apply', $checksum)->assertValidResponse(200);

    /** @var string $uuid */
    $uuid = DB::table('employees')->value('uuid');
    WorkforceFixtures::terminate($uuid);

    return $uuid;
}

it('no readmite a quien la linea encuentra solo por su correo', function (): void {
    // Sin columna de documento, el correo empareja (regla dura 12), pero un
    // correo se comparte y cambia de manos: readmitir por el pondria en la ficha
    // de Youssef a quien quiza no es Youssef.
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    $youssef = youssefConCorreoDeBaja($token);

    $fichero = "nombre,apellidos,correo,fecha_alta\nYoussef,Amrani,youssef@hotel.example,2026-09-01\n";

    $comprobado = importarConBaja($token, ImportFiles::csv($fichero))
        ->assertValidResponse(200)
        ->assertJsonPath('summary.rehire', 0)
        ->assertJsonPath('summary.reject', 1)
        ->assertJsonPath('rows.0.outcome', 'reject')
        ->assertJsonPath('rows.0.messages.0.code', 'rehire_identity_mismatch')
        ->assertJsonPath('rows.0.messages.0.severity', 'error')
        ->assertJsonMissingPath('rehire_digest');

    importarConBaja($token, ImportFiles::csv($fichero), 'apply', huellaDeLaImportacionConBaja($comprobado))
        ->assertValidResponse(200)
        ->assertJsonPath('summary.rehire', 0);

    expect(DB::table('employees')->where('uuid', $youssef)->value('status'))->toBe('terminated')
        ->and(DB::table('audit_log')->where('action', 'employee.rehired')->count())->toBe(0);
})->group('RF-GP-05', 'RN-27');

it('no readmite si el nombre de la linea no es el de la ficha, aunque coincida el documento', function (): void {
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    $youssef = youssefImportado($token);
    WorkforceFixtures::terminate($youssef);

    importarConBaja($token, ImportFiles::csv("nombre,apellidos,dni,fecha_alta\nMarta,Vidal,12345678Z,2026-09-01\n"))
        ->assertValidResponse(200)
        ->assertJsonPath('rows.0.outcome', 'reject')
        ->assertJsonCount(1, 'rows.0.messages')
        ->assertJsonPath('rows.0.messages.0.code', 'rehire_identity_mismatch')
        ->assertJsonPath('rows.0.messages.0.detail', 'Esta línea coincide con una persona de baja, pero no con seguridad '
            .'para darla de alta de nuevo: o solo coincide el correo, o el nombre no es el de su ficha. '
            .'Escribe su documento y su nombre tal como están en la ficha, o hazlo desde su ficha.');

    expect(DB::table('employees')->where('uuid', $youssef)->value('status'))->toBe('terminated');
})->group('RF-GP-05', 'RN-27');

it('readmite por documento aunque el nombre cambie de mayusculas o tildes, y avisa de que no aplica el correo nuevo', function (): void {
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    $youssef = youssefConCorreoDeBaja($token);

    $fichero = "nombre,apellidos,dni,correo,fecha_alta\nYOUSSEF,Amraní,12345678Z,otro@hotel.example,2026-09-01\n";

    $comprobado = importarConBaja($token, ImportFiles::csv($fichero))
        ->assertValidResponse(200)
        ->assertJsonPath('rows.0.outcome', 'rehire')
        ->assertJsonPath('rows.0.changes', [])
        ->assertJsonPath('rows.0.messages.0.code', 'rehire_proposed')
        ->assertJsonPath('rows.0.messages.1.code', 'rehire_ignores_identity_changes')
        ->assertJsonPath('rows.0.messages.1.severity', 'warning');

    importarConBaja($token, ImportFiles::csv($fichero), 'apply', huellaDeLaImportacionConBaja($comprobado), rehireDigestDe($comprobado))
        ->assertValidResponse(200)
        ->assertJsonPath('summary.rehire', 1);

    expect(DB::table('employees')->where('uuid', $youssef)->first(['status', 'first_name', 'last_name', 'email']))
        ->toEqual((object) ['status' => 'active', 'first_name' => 'Youssef', 'last_name' => 'Amrani', 'email' => 'youssef@hotel.example']);
})->group('RF-GP-05', 'RN-27');

it('si la baja se aplica entre comprobar y aplicar y la linea pasa a readmitir, responde 409 y no escribe nada', function (): void {
    // RN-27, ADR-046 §5: la validacion dijo `update` (sin `rehire_digest`); la
    // baja llega antes de aplicar y la segunda comprobacion veria una
    // readmision que nadie ha revisado. La aplicacion entera se detiene.
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    $youssef = youssefImportado($token);

    FrozenTime::at('2026-10-20 10:00:00');
    $fichero = "nombre,apellidos,dni,fecha_alta\nYoussef,Amrani,12345678Z,2026-10-15\nMarta,Vidal,87654321X,2026-02-01\n";

    $comprobado = importarConBaja($token, ImportFiles::csv($fichero))
        ->assertValidResponse(200)
        ->assertJsonPath('rows.0.outcome', 'unchanged')
        ->assertJsonPath('summary.rehire', 0)
        ->assertJsonMissingPath('rehire_digest');

    Api::as($token)
        ->post('/api/v1/employees/'.$youssef.'/offboard', ['terminated_at' => '2026-10-02'])
        ->assertValidResponse(200);

    $empleados = DB::table('employees')->count();
    $asientos = DB::table('audit_log')->count();

    importarConBaja($token, ImportFiles::csv($fichero), 'apply', huellaDeLaImportacionConBaja($comprobado))
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:conflict');

    expect(DB::table('employees')->count())->toBe($empleados)
        ->and(DB::table('employees')->where('uuid', $youssef)->value('status'))->toBe('terminated')
        ->and(DB::table('audit_log')->count())->toBe($asientos);

    // Con el resumen nuevo, si.
    $otraVez = importarConBaja($token, ImportFiles::csv($fichero))
        ->assertValidResponse(200)
        ->assertJsonPath('rows.0.outcome', 'rehire');

    importarConBaja($token, ImportFiles::csv($fichero), 'apply', huellaDeLaImportacionConBaja($otraVez), rehireDigestDe($otraVez))
        ->assertValidResponse(200)
        ->assertJsonPath('summary.rehire', 1);
})->group('RF-GP-05', 'RN-27');

it('si la persona se readmite por otra via entre comprobar y aplicar, responde 409 aunque traiga la huella', function (): void {
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    $youssef = youssefImportado($token);
    WorkforceFixtures::terminate($youssef);

    $fichero = "nombre,apellidos,dni,fecha_alta\nYoussef,Amrani,12345678Z,2026-09-01\n";
    $comprobado = importarConBaja($token, ImportFiles::csv($fichero))->assertValidResponse(200)->assertJsonPath('rows.0.outcome', 'rehire');

    Api::as($token)
        ->post('/api/v1/employees/'.$youssef.'/rehire', ['hired_at' => '2026-09-01'])
        ->assertValidResponse(200);

    $asientos = DB::table('audit_log')->count();

    importarConBaja($token, ImportFiles::csv($fichero), 'apply', huellaDeLaImportacionConBaja($comprobado), rehireDigestDe($comprobado))
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:conflict');

    expect(DB::table('audit_log')->count())->toBe($asientos)
        ->and(DB::table('employment_periods')->where('employee_id', DB::table('employees')->where('uuid', $youssef)->value('id'))->count())->toBe(2);
})->group('RF-GP-05', 'RN-27');

it('una readmision validada no se aplica sin su huella', function (): void {
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    $youssef = youssefImportado($token);
    WorkforceFixtures::terminate($youssef);

    $fichero = "nombre,apellidos,dni,fecha_alta\nYoussef,Amrani,12345678Z,2026-09-01\n";
    $comprobado = importarConBaja($token, ImportFiles::csv($fichero))->assertValidResponse(200);

    importarConBaja($token, ImportFiles::csv($fichero), 'apply', huellaDeLaImportacionConBaja($comprobado))
        ->assertValidResponse(409);

    importarConBaja($token, ImportFiles::csv($fichero), 'apply', huellaDeLaImportacionConBaja($comprobado), str_repeat('0', 64))
        ->assertValidResponse(409);

    expect(DB::table('employees')->where('uuid', $youssef)->value('status'))->toBe('terminated');
})->group('RF-GP-05', 'RN-27');
