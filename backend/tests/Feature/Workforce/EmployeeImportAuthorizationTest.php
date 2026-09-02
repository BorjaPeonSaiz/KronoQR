<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\ImportFiles;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Autorizacion negativa de `POST /api/v1/employees/import` (RF-GP-05, regla dura
 * 18): **un `403` por cada rol que no debe entrar**.
 *
 * EL CONJUNTO ES EL MISMO QUE EL DEL ALTA INDIVIDUAL, y tiene que serlo: la
 * importacion hace exactamente eso, dar de alta y modificar fichas, solo que
 * cuarenta a la vez. Uno mas estrecho no protegeria nada —quien puede dar de
 * alta una por una puede dar de alta cuarenta— y uno mas ancho daria la plantilla
 * entera a quien solo puede leerla.
 *
 * EL ANEXO B MARCA ESTE ENDPOINT COMO `[rol: rrhh]` Y AQUI ENTRA TAMBIEN `admin`,
 * igual que en `POST /employees`. No es una ampliacion arbitraria: la importacion
 * es el paso de plantilla del asistente de puesta en marcha (RF-PD-03), y en ese
 * momento la unica cuenta que existe es el primer administrador. Con `rrhh` a
 * secas, ese paso seria inalcanzable el dia de la instalacion.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

function anImportFile(): UploadedFile
{
    return ImportFiles::csv("nombre,apellidos,dni,fecha_alta\nYoussef,Amrani,12345678Z,2026-01-15\n");
}

it('no deja importar sin token', function (): void {
    WorkforceFixtures::site();

    Api::guest()
        ->upload('/api/v1/employees/import', ['mode' => 'validate'], ['file' => anImportFile()])
        ->assertValidResponse(401);
})->group('RF-GP-05', 'RS-04');

it('no deja importar a quien no mantiene la plantilla', function (UserRole $role): void {
    // `auditor` no lleva `employees:*` y se queda en el middleware; el
    // `responsable_departamento` lleva `employees:read` desde la 2.1 y tampoco
    // alcanza la familia de escritura. Los dos son 403 y por dos controles
    // distintos (ambito del §7.3 + policy), que es lo que la regla dura 18 pide.
    WorkforceFixtures::site();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole($role)))
        ->upload('/api/v1/employees/import', ['mode' => 'validate'], ['file' => anImportFile()])
        ->assertValidResponse(403);

    expect(DB::table('employees')->count())->toBe(0);
})->with([
    'auditor' => UserRole::AUDITOR,
    'responsable_departamento' => UserRole::RESPONSABLE_DEPARTAMENTO,
])->group('RF-GP-05', 'RS-04');

it('deja importar a rrhh y al administrador', function (UserRole $role): void {
    WorkforceFixtures::site();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole($role)))
        ->upload('/api/v1/employees/import', ['mode' => 'validate'], ['file' => anImportFile()])
        ->assertValidResponse(200);
})->with([
    'rrhh' => UserRole::RRHH,
    'admin' => UserRole::ADMIN,
])->group('RF-GP-05');

it('no deja al quiosco acercarse a la importacion', function (): void {
    // Su token lleva `scan:write`, `roster:read` y `heartbeat:write`, y ninguno
    // es `employees:*`: se queda en el middleware. Un token de quiosco robado no
    // da acceso a la plantilla por ninguna via (RS-04).
    WorkforceFixtures::site();

    Api::as(ManagementUsers::kioskToken())
        ->upload('/api/v1/employees/import', ['mode' => 'validate'], ['file' => anImportFile()])
        ->assertValidResponse(403);
})->group('RF-GP-05', 'RS-04');

it('rechaza un fichero mas grande que el limite, diciendo que hacer', function (): void {
    // El borde de Nginx corta en 8 MB y devuelve un `413` sin cuerpo: quien lo
    // recibe ve «error de red». Este limite es mas bajo, se comprueba en la
    // aplicacion y produce un `422` con el campo señalado.
    WorkforceFixtures::site();

    config()->set('workforce.import.max_file_kilobytes', 1);

    $grande = ImportFiles::csv(
        "nombre,apellidos,dni,fecha_alta\n".str_repeat("Youssef,Amrani,12345678Z,2026-01-15\n", 200)
    );

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->upload('/api/v1/employees/import', ['mode' => 'validate'], ['file' => $grande])
        ->assertValidResponse(422)
        ->assertJsonStructure(['errors' => ['file']]);
})->group('RF-GP-05');

it('avisa de que el fichero venia truncado y se niega a aplicarlo a medias', function (): void {
    // Aplicar la mitad de una plantilla es el fallo que nadie detecta hasta que
    // falta gente delante de la tablet a las 06:00.
    WorkforceFixtures::site();
    config()->set('workforce.import.max_rows', 2);

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    $csv = "nombre,apellidos,dni,fecha_alta\n"
        ."Uno,Apellido,11111111H,2026-01-15\n"
        ."Dos,Apellido,22222222J,2026-01-15\n"
        ."Tres,Apellido,33333333P,2026-01-15\n";

    $response = Api::as($token)
        ->upload('/api/v1/employees/import', ['mode' => 'validate'], ['file' => ImportFiles::csv($csv)])
        ->assertValidResponse(200)
        ->assertJsonPath('truncated', true)
        ->assertJsonPath('file.rows', 2);

    $checksum = (string) $response->json('file.sha256');

    Api::as($token)->upload(
        '/api/v1/employees/import',
        ['mode' => 'apply', 'confirm_checksum' => $checksum],
        ['file' => ImportFiles::csv($csv)],
    )->assertValidResponse(422)->assertJsonStructure(['errors' => ['file']]);

    expect(DB::table('employees')->count())->toBe(0);
})->group('RF-GP-05');
