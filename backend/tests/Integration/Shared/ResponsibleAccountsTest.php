<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;

/*
 * Quien puede firmar una accion de consola con relevancia legal, contra
 * `users` y las tablas de roles de verdad.
 *
 * Sin roles es lo que acepta la revision de una purga (decision D7): cualquier
 * cuenta de gestion activa. Con roles es lo que exige la correccion de la zona
 * del centro (RN-23): los que pueden modificar el centro por la API.
 */

uses(RefreshDatabase::class);

function responsablesPuerto(): ResponsibleAccounts
{
    return app(ResponsibleAccounts::class);
}

it('sin roles acepta cualquier cuenta de gestion activa', function (UserRole $rol): void {
    $cuenta = ManagementUsers::withRole($rol)->id;

    expect(responsablesPuerto()->isActiveManagementAccount($cuenta))->toBeTrue();
})->with([
    'admin' => [UserRole::ADMIN],
    'rrhh' => [UserRole::RRHH],
    'responsable' => [UserRole::RESPONSABLE_DEPARTAMENTO],
    'auditor' => [UserRole::AUDITOR],
])->group('RL-04');

it('con roles solo acepta las cuentas que tienen alguno', function (UserRole $rol, bool $acepta): void {
    $cuenta = ManagementUsers::withRole($rol)->id;

    expect(responsablesPuerto()->isActiveManagementAccount($cuenta, [UserRole::ADMIN, UserRole::RRHH]))->toBe($acepta);
})->with([
    'admin' => [UserRole::ADMIN, true],
    'rrhh' => [UserRole::RRHH, true],
    'responsable' => [UserRole::RESPONSABLE_DEPARTAMENTO, false],
    'auditor' => [UserRole::AUDITOR, false],
    'empleado' => [UserRole::EMPLEADO, false],
])->group('RN-23');

it('no acepta una cuenta dada de baja ni una que no existe', function (): void {
    $cuenta = ManagementUsers::withRole(UserRole::ADMIN)->id;
    DB::table('users')->where('id', $cuenta)->update(['is_active' => false]);

    expect(responsablesPuerto()->isActiveManagementAccount($cuenta))->toBeFalse()
        ->and(responsablesPuerto()->isActiveManagementAccount($cuenta, [UserRole::ADMIN]))->toBeFalse()
        ->and(responsablesPuerto()->isActiveManagementAccount(999_999))->toBeFalse();
})->group('RL-04', 'RN-23');
