<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * RF-GP-01 y RS-05 — listar la plantilla es divulgar datos de terceros.
 *
 * El mismo criterio que `GET /credentials/status` y `/kiosk/roster`: lo que sale
 * es un CONJUNTO de personas con nombre, codigo, centro y departamento, y RL-15
 * exige poder decir despues que se llevo cada cuenta. La ficha individual no
 * repite el asiento a proposito —quien puede abrirla puede listar el indice, y
 * el asiento del indice ya lo dice—.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * @return array{token: string, site: int, department: int}
 */
function directoryContext(int $employees = 3): array
{
    $site = WorkforceFixtures::site();
    $department = WorkforceFixtures::department($site);

    for ($i = 0; $i < $employees; $i++) {
        WorkforceFixtures::employee($site, $department);
    }

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'site' => $site,
        'department' => $department,
    ];
}

it('deja asiento al listar la plantilla', function (): void {
    $contexto = directoryContext();

    expect(DB::table('audit_log')->count())->toBe(0);

    Api::as($contexto['token'])
        ->get('/api/v1/employees')
        ->assertValidResponse(200);

    $asiento = DB::table('audit_log')->orderBy('id')->first();

    expect($asiento)->not->toBeNull();

    /** @var object{action: string, actor_type: string, subject_type: string, payload: string} $asiento */
    $payload = json_decode($asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($asiento->action)->toBe('personal_data.accessed')
        ->and($asiento->actor_type)->toBe('user')
        ->and($asiento->subject_type)->toBe('personal_data')
        ->and($payload)->toMatchArray([
            'dataset' => 'employee_directory',
            'record_count' => 3,
        ]);
})->group('RF-GP-01', 'RS-05', 'RL-15');

it('cuenta lo que sale en la pagina, no lo que cumple el filtro', function (): void {
    // El alcance de una brecha lo define lo entregado. Con `per_page=2` salen
    // dos filas aunque el filtro case con tres, y decir «tres» seria exagerar la
    // brecha exactamente igual de mal que quedarse corto.
    $contexto = directoryContext();

    Api::as($contexto['token'])
        ->get('/api/v1/employees', ['per_page' => 2, 'site_id' => $contexto['site']])
        ->assertValidResponse(200);

    $asiento = DB::table('audit_log')->orderBy('id')->first();

    expect($asiento)->not->toBeNull();

    /** @var object{payload: string} $asiento */
    $payload = json_decode($asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'record_count' => 2,
        'site_id' => $contexto['site'],
        'per_page' => 2,
        'page' => 1,
    ])
        // Regla dura 21: el alcance, nunca lo divulgado.
        ->and($asiento->payload)->not->toContain('Persona')
        ->and($asiento->payload)->not->toContain('De Prueba');
})->group('RF-GP-01', 'RS-05');

it('no repite el asiento por cada ficha abierta', function (): void {
    // Criterio explicito, no olvido: el indice ya consta y duplicarlo por ficha
    // llenaria `audit_log` de la operativa ordinaria de RRHH sin cambiar la
    // respuesta a «que se llevo esa cuenta».
    $contexto = directoryContext(1);

    $uuid = DB::table('employees')->value('uuid');

    Api::as($contexto['token'])
        ->get('/api/v1/employees/'.(is_string($uuid) ? $uuid : ''))
        ->assertValidResponse(200);

    expect(DB::table('audit_log')->count())->toBe(0);
})->group('RF-GP-01', 'RS-05');
