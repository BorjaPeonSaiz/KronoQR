<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Domain\Model\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `employees.teleworking` entre el modelo de dominio y PostgreSQL (RF-GP-01):
 * el repositorio lo escribe y lo lee sin perderlo, el filtro del listado actua
 * en SQL sobre la plantilla entera y la columna no admite `NULL` aunque se
 * escriba sin pasar por el dominio.
 */

uses(RefreshDatabase::class);

function teletrabajoRepositorio(): EmployeeRepository
{
    return app(EmployeeRepository::class);
}

function teletrabajoCargada(string $uuid): Employee
{
    $employee = teletrabajoRepositorio()->findByUuid($uuid);

    expect($employee)->toBeInstanceOf(Employee::class);

    /** @var Employee $employee */
    return $employee;
}

it('lee false en una ficha que nadie ha marcado', function (): void {
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site());

    expect(teletrabajoCargada($uuid)->teleworking)->toBeFalse();
})->group('RF-GP-01');

it('guarda y vuelve a leer la marca en los dos sentidos', function (): void {
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site());

    teletrabajoRepositorio()->save(teletrabajoCargada($uuid)->updateProfile(teleworking: true));

    expect(teletrabajoCargada($uuid)->teleworking)->toBeTrue()
        ->and(DB::table('employees')->where('uuid', $uuid)->value('teleworking'))->toBeTrue();

    teletrabajoRepositorio()->save(teletrabajoCargada($uuid)->updateProfile(teleworking: false));

    expect(teletrabajoCargada($uuid)->teleworking)->toBeFalse();
})->group('RF-GP-01');

it('filtra por la marca en la consulta, con el recuento del mismo filtro', function (): void {
    $site = WorkforceFixtures::site();
    $remota = WorkforceFixtures::employee($site, lastName: 'Remota');
    WorkforceFixtures::employee($site, lastName: 'Presencial A');
    WorkforceFixtures::employee($site, lastName: 'Presencial B');

    DB::table('employees')->where('uuid', $remota)->update(['teleworking' => true]);

    $scope = AccessScope::unrestricted();
    $repository = teletrabajoRepositorio();

    $si = $repository->search($scope, null, null, null, null, true, 25, 0);
    $no = $repository->search($scope, null, null, null, null, false, 25, 0);
    $todos = $repository->search($scope, null, null, null, null, null, 25, 0);

    expect(array_map(static fn (Employee $e): string => $e->uuid, $si))->toBe([$remota])
        ->and($repository->countMatching($scope, null, null, null, null, true))->toBe(1)
        ->and($no)->toHaveCount(2)
        ->and($repository->countMatching($scope, null, null, null, null, false))->toBe(2)
        ->and($todos)->toHaveCount(3)
        ->and($repository->countMatching($scope, null, null, null, null, null))->toBe(3);
})->group('RF-GP-01');

it('rechaza NULL en la columna aunque se escriba sin pasar por el dominio', function (): void {
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site());

    expect(fn (): int => DB::table('employees')->where('uuid', $uuid)->update(['teleworking' => null]))
        ->toThrow(QueryException::class, 'teleworking');
})->group('RF-GP-01');
