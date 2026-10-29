<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **EL `404` DE LAS OPERACIONES QUE VALIDAN EL CUERPO ANTES DE BUSCAR**
 * (Q3; RQ-06).
 *
 * El barrido del `404` (`SharedResponsesSweepTest`) pide cada operacion sin
 * cuerpo, y estas responden antes su `422`: el cuerpo se valida antes de mirar
 * si el recurso existe. Aqui cada una lleva un cuerpo valido y un recurso que
 * no existe, y lo que se valida contra el contrato es ese `404`. La lista de
 * las que el barrido aparta, con el motivo, esta en `ContractSweep::SET_ASIDE`.
 *
 * Todas con un `admin` en una instalacion con centro: la autorizacion pasa y lo
 * unico que falta es el recurso de la ruta.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/** Un `uuid` v7 que no es de nada. */
const MISSING_RESOURCE_UUID = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90';

function missingResourceAdmin(): string
{
    WorkforceFixtures::site();

    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
}

it('no corrige una ausencia que no existe, con el 404 del contrato', function (): void {
    $admin = missingResourceAdmin();

    $respuesta = Api::as($admin)->patch('/api/v1/absences/'.MISSING_RESOURCE_UUID, ['reason' => 'Correccion de una ausencia que no existe.']);

    $respuesta->assertValidResponse(404);
})->group('RQ-06', 'RF-GP-04');

it('no anula una ausencia que no existe, con el 404 del contrato', function (): void {
    $admin = missingResourceAdmin();

    $respuesta = Api::as($admin)->post('/api/v1/absences/'.MISSING_RESOURCE_UUID.'/void', ['reason' => 'Ya no procede.']);

    $respuesta->assertValidResponse(404);
})->group('RQ-06', 'RF-GP-04');

it('no anula un tramo que no existe, con el 404 del contrato', function (): void {
    $admin = missingResourceAdmin();

    $respuesta = Api::as($admin)->post('/api/v1/shift-entries/'.MISSING_RESOURCE_UUID.'/void', ['reason_code' => 'ERROR_DE_ESCANEO_DUPLICADO']);

    $respuesta->assertValidResponse(404);
})->group('RQ-06', 'RF-PA-04');

it('no da contrato a una persona que no existe, con el 404 del contrato', function (): void {
    $admin = missingResourceAdmin();

    $respuesta = Api::as($admin)->post('/api/v1/employees/'.MISSING_RESOURCE_UUID.'/contracts', [
        'weekly_hours' => 40,
        'schedule_type' => 'turnos',
        'valid_from' => '2026-03-01',
    ]);

    $respuesta->assertValidResponse(404);
})->group('RQ-06', 'RF-GP-02');

it('no da de baja a una persona que no existe, con el 404 del contrato', function (): void {
    $admin = missingResourceAdmin();

    $respuesta = Api::as($admin)->post('/api/v1/employees/'.MISSING_RESOURCE_UUID.'/offboard', ['terminated_at' => '2026-09-14']);

    $respuesta->assertValidResponse(404);
})->group('RQ-06', 'RF-GP-03');

it('no renombra un departamento que no existe, con el 404 del contrato', function (): void {
    $admin = missingResourceAdmin();

    $respuesta = Api::as($admin)->patch('/api/v1/departments/999999', ['name' => 'Pisos']);

    $respuesta->assertValidResponse(404);
})->group('RQ-06', 'RF-GP-01');

it('no renombra un departamento con el nombre de otro del centro, con el 409 del contrato', function (): void {
    $admin = missingResourceAdmin();
    Api::as($admin)->post('/api/v1/departments', ['name' => 'Pisos'])->assertValidResponse(201);
    $cocina = Api::as($admin)->post('/api/v1/departments', ['name' => 'Cocina'])->assertValidResponse(201)->json('id');

    $respuesta = Api::as($admin)->patch('/api/v1/departments/'.$cocina, ['name' => 'Pisos']);

    $respuesta->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:conflict');
})->group('RQ-06', 'RF-GP-01');

it('no cambia el centro antes de la puesta en marcha, con el 404 del contrato', function (): void {
    $admin = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    $respuesta = Api::as($admin)->patch('/api/v1/site', ['name' => 'Hotel Marina']);

    $respuesta->assertValidResponse(404);
})->group('RQ-06', 'RF-GP-01', 'RF-PD-03');
