<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;

/*
 * El esquema del segundo factor tal y como queda en la base de datos de una
 * instalacion (**RS-06**, migracion `add_two_factor_to_users_table`).
 *
 * **Por que es integracion y no unitaria.** Lo que se comprueba no es una regla:
 * es que la instalacion de un cliente arranca con las columnas —y que el secreto
 * **no queda en claro** en ellas—, y eso solo se ve ejecutando las migraciones y
 * escribiendo de verdad.
 */

uses(RefreshDatabase::class);

it('anade las tres columnas del segundo factor, todas opcionales', function (): void {
    // Nullable a proposito: es un *expand* puro sobre una tabla con datos (doc 02
    // §10.4). Una columna obligatoria sin valor por omision haria fallar la
    // migracion en la base del cliente y no en la de desarrollo, que esta vacia.
    foreach (['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_last_slice'] as $column) {
        expect(Schema::hasColumn('users', $column))->toBeTrue($column);
    }

    $obligatorias = DB::table('information_schema.columns')
        ->where('table_name', 'users')
        ->whereIn('column_name', ['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_last_slice'])
        ->where('is_nullable', 'NO')
        ->pluck('column_name')
        ->all();

    expect($obligatorias)->toBe([]);
})->group('RS-06', 'RNF-D-04');

it('no deja el secreto TOTP en claro en la columna', function (): void {
    // Regla dura del cifrado en reposo aplicada a una credencial: quien consiga
    // una copia de la base de datos del cliente no puede generar los codigos de
    // nadie sin tener ademas `APP_KEY`, que vive en el entorno.
    $user = ManagementUsers::withRole(UserRole::RRHH);
    $secret = ManagementUsers::withActiveSecondFactor($user);

    $almacenado = DB::table('users')->where('uuid', $user->uuid)->value('two_factor_secret');

    expect($almacenado)->toBeString();

    $criptograma = is_string($almacenado) ? $almacenado : '';

    expect($criptograma)->not->toBe($secret)
        ->and($criptograma)->not->toContain($secret);

    // Y sigue leyendose bien por el modelo, que es lo que hace que el cifrado no
    // sea una molestia sino un detalle de persistencia.
    expect(User::query()->where('uuid', $user->uuid)->value('two_factor_secret'))->toBe($secret);
})->group('RS-06');

it('siembra el ambito de lectura de plantilla y se lo da a los tres roles que leen', function (): void {
    // RF-ID-03: la migracion `grant_read_ability` es la que hace que el
    // responsable pueda listar su gente sin ganar la escritura.
    $roles = DB::table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('permissions.name', TokenAbility::EMPLOYEES_READ->value)
        ->orderBy('roles.name')
        ->pluck('roles.name')
        ->all();

    expect($roles)->toBe(['admin', 'responsable_departamento', 'rrhh']);
})->group('RF-ID-03', 'RF-ID-02');
