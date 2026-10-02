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
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\ImportFiles;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LA IMPORTACION NO TOCA A QUIEN ESTA DE BAJA** (RF-GP-05, RN-14, ADR-046 §5).
 *
 * La linea de una persona dada de baja se rechaza al comprobar con
 * `employee_terminated` y el resto del fichero se aplica. Antes esa linea
 * llegaba a la modificacion y tumbaba el lote entero con un `409` que no nombraba
 * ninguna linea.
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
function importarConBaja(string $token, UploadedFile $file, string $mode = 'validate', ?string $checksum = null): TestResponse
{
    $fields = ['mode' => $mode];

    if ($checksum !== null) {
        $fields['confirm_checksum'] = $checksum;
    }

    return Api::as($token)->upload('/api/v1/employees/import', $fields, ['file' => $file]);
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

it('rechaza la linea de una persona de baja con employee_terminated y aplica el resto', function (): void {
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    $youssef = youssefImportado($token);
    WorkforceFixtures::terminate($youssef);

    $comprobado = importarConBaja($token, ImportFiles::csv(ficheroConUnaBaja()))
        ->assertValidResponse(200)
        ->assertJsonPath('summary.reject', 1)
        ->assertJsonPath('summary.create', 1)
        ->assertJsonPath('rows.0.outcome', 'reject')
        ->assertJsonPath('rows.0.messages.0.code', 'employee_terminated')
        ->assertJsonPath('rows.0.messages.0.severity', 'error')
        ->assertJsonPath('rows.0.messages.0.detail', 'Esta persona está dada de baja. La importación no modifica su ficha '
            .'ni la vuelve a dar de alta; quita la línea del fichero.');

    importarConBaja($token, ImportFiles::csv(ficheroConUnaBaja()), 'apply', huellaDeLaImportacionConBaja($comprobado))
        ->assertValidResponse(200)
        ->assertJsonPath('summary.create', 1)
        ->assertJsonPath('summary.reject', 1);

    expect(DB::table('employees')->count())->toBe(2)
        ->and(DB::table('employees')->where('uuid', $youssef)->first(['status', 'last_name']))
        ->toEqual((object) ['status' => 'terminated', 'last_name' => 'Amrani'])
        ->and(DB::table('employees')->where('last_name', 'Vidal')->value('status'))->toBe('active');
})->group('RF-GP-05', 'RN-14');

it('lo dice en ingles a quien pide ingles', function (): void {
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    WorkforceFixtures::terminate(youssefImportado($token));

    Api::as($token)
        ->withHeaders(['Accept-Language' => 'en'])
        ->upload('/api/v1/employees/import', ['mode' => 'validate'], ['file' => ImportFiles::csv(ficheroConUnaBaja())])
        ->assertValidResponse(200)
        ->assertJsonPath('rows.0.messages.0.detail', 'This person has been offboarded. The import does not change their '
            .'record or make them employed again; remove the line from the file.');
})->group('RF-GP-05', 'RN-14');

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
        ->post('/api/v1/employees/'.$youssef.'/offboard', ['terminated_at' => '2026-10-02'])
        ->assertValidResponse(200);

    $empleados = DB::table('employees')->count();
    $asientos = DB::table('audit_log')->count();

    expect(fn () => app(ApplyEmployeeImport::class)->handle($informe))
        ->toThrow(EmployeeAlreadyTerminated::class);

    expect(DB::table('employees')->count())->toBe($empleados)
        ->and(DB::table('employees')->where('uuid', $youssef)->first(['status', 'last_name']))
        ->toEqual((object) ['status' => 'terminated', 'last_name' => 'Amrani'])
        ->and(DB::table('audit_log')->count())->toBe($asientos);
})->group('RF-GP-05', 'RN-14');

it('si la baja llega entre la peticion de comprobar y la de aplicar, la aplicacion vuelve a comprobar y no la toca', function (): void {
    // `apply` vuelve a planificar el fichero antes de escribir y solo compara su
    // huella, no el informe: la linea de la persona que se dio de baja entre las
    // dos peticiones sale `employee_terminated` y el resto se aplica.
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    $youssef = youssefImportado($token);

    $comprobado = importarConBaja($token, ImportFiles::csv(ficheroConUnaBaja()))
        ->assertValidResponse(200)
        ->assertJsonPath('summary.update', 1);

    Api::as($token)
        ->post('/api/v1/employees/'.$youssef.'/offboard', ['terminated_at' => '2026-10-02'])
        ->assertValidResponse(200);

    importarConBaja($token, ImportFiles::csv(ficheroConUnaBaja()), 'apply', huellaDeLaImportacionConBaja($comprobado))
        ->assertValidResponse(200)
        ->assertJsonPath('rows.0.messages.0.code', 'employee_terminated')
        ->assertJsonPath('summary.create', 1);

    expect(DB::table('employees')->where('uuid', $youssef)->first(['status', 'last_name']))
        ->toEqual((object) ['status' => 'terminated', 'last_name' => 'Amrani'])
        ->and(DB::table('audit_log')->where('action', 'employee.updated')->where('payload->employee_uuid', $youssef)->count())
        ->toBe(0);
})->group('RF-GP-05', 'RN-14');
