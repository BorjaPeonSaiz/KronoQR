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
 * `GET` y `POST /api/v1/employees/{uuid}/contracts` — contratos historizados
 * (**RF-GP-02**, tarea 2.8), extremo a extremo y contra el contrato de la API.
 *
 * Lo que estas pruebas defienden:
 *
 *   - **Regla dura 5**: registrar un contrato nuevo no sobrescribe el anterior,
 *     lo cierra. Los dos siguen en la tabla.
 *   - **RF-IN-03**: la serie queda sin huecos y sin solapes, que es lo unico que
 *     hace que «¿cuantas horas tenia contratadas el 14 de marzo?» tenga UNA
 *     respuesta.
 *   - **Regla dura 6**: el cambio queda en `audit_log` con su accion propia.
 *   - La invariante del esquema —`employment_contracts_no_overlap`— se traduce a
 *     `409` y no a un `500`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * @return array{token: string, employee: string}
 */
function contextoDeContrato(): array
{
    $site = WorkforceFixtures::site('Hotel de plantilla');

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'employee' => WorkforceFixtures::employee($site),
    ];
}

it('registra el primer contrato abierto de una persona', function (): void {
    $contexto = contextoDeContrato();

    Api::as($contexto['token'])
        ->post('/api/v1/employees/'.$contexto['employee'].'/contracts', [
            'weekly_hours' => 37.5,
            'annual_hours' => 1670,
            'schedule_type' => 'partida',
            'valid_from' => '2026-01-01',
        ])
        ->assertValidRequest()
        ->assertValidResponse(201)
        ->assertJsonPath('employee_uuid', $contexto['employee'])
        ->assertJsonPath('weekly_hours', 37.5)
        ->assertJsonPath('annual_hours', 1670)
        ->assertJsonPath('schedule_type', 'partida')
        ->assertJsonPath('valid_from', '2026-01-01')
        // Abierto: lo cerrara el siguiente, no quien lo da de alta.
        ->assertJsonPath('valid_to', null)
        ->assertJsonPath('is_current', true);
})->group('RF-GP-02');

it('cierra el contrato anterior al registrar uno nuevo, sin borrar nada', function (): void {
    // Regla dura 5 y el motivo de que esta tabla exista: si el informe comparara
    // contra el ultimo contrato, marzo pasaria a medirse con las horas de julio
    // en cuanto alguien firmara un anexo.
    $contexto = contextoDeContrato();

    Api::as($contexto['token'])
        ->post('/api/v1/employees/'.$contexto['employee'].'/contracts', [
            'weekly_hours' => 20,
            'schedule_type' => 'turnos',
            'valid_from' => '2026-01-01',
        ])
        ->assertValidResponse(201);

    Api::as($contexto['token'])
        ->post('/api/v1/employees/'.$contexto['employee'].'/contracts', [
            'weekly_hours' => 40,
            'schedule_type' => 'turnos',
            'valid_from' => '2026-03-16',
        ])
        ->assertValidResponse(201)
        ->assertJsonPath('valid_to', null);

    Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['employee'].'/contracts')
        ->assertValidResponse(200)
        ->assertJsonCount(2, 'data')
        // Del mas antiguo al mas reciente, y el primero ya cerrado el dia
        // ANTERIOR al inicio del segundo: ni hueco ni solape.
        ->assertJsonPath('data.0.weekly_hours', 20)
        ->assertJsonPath('data.0.valid_to', '2026-03-15')
        ->assertJsonPath('data.0.is_current', false)
        ->assertJsonPath('data.1.weekly_hours', 40)
        ->assertJsonPath('data.1.valid_from', '2026-03-16')
        ->assertJsonPath('data.1.is_current', true);
})->group('RF-GP-02');

it('rechaza con 409 un contrato que empieza antes que el vigente', function (): void {
    // La invariante que sostiene RF-IN-03. Es `409` y no `422`: el cuerpo es
    // valido, lo que no encaja es el estado, y la accion siguiente es releer los
    // contratos de esa persona en vez de reescribir el formulario.
    $contexto = contextoDeContrato();

    Api::as($contexto['token'])
        ->post('/api/v1/employees/'.$contexto['employee'].'/contracts', [
            'weekly_hours' => 40,
            'schedule_type' => 'turnos',
            'valid_from' => '2026-03-01',
        ])
        ->assertValidResponse(201);

    Api::as($contexto['token'])
        ->post('/api/v1/employees/'.$contexto['employee'].'/contracts', [
            'weekly_hours' => 20,
            'schedule_type' => 'turnos',
            'valid_from' => '2026-02-01',
        ])
        ->assertStatus(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:conflict');

    // Y no ha quedado nada a medias: sigue habiendo un solo contrato.
    Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['employee'].'/contracts')
        ->assertValidResponse(200)
        ->assertJsonCount(1, 'data');
})->group('RF-GP-02');

it('rechaza con 422 unas horas que no describen ninguna jornada', function (array $cuerpo): void {
    $contexto = contextoDeContrato();

    Api::as($contexto['token'])
        ->post('/api/v1/employees/'.$contexto['employee'].'/contracts', $cuerpo)
        ->assertStatus(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed');
})->with([
    'cero horas' => [['weekly_hours' => 0, 'schedule_type' => 'turnos', 'valid_from' => '2026-01-01']],
    'mas de 168' => [['weekly_hours' => 200, 'schedule_type' => 'turnos', 'valid_from' => '2026-01-01']],
    'tipo de jornada inventado' => [['weekly_hours' => 40, 'schedule_type' => 'flexible', 'valid_from' => '2026-01-01']],
    'fecha que no existe' => [['weekly_hours' => 40, 'schedule_type' => 'turnos', 'valid_from' => '2026-02-31']],
    // `valid_to` no se admite: dejarlo escribir permitiria crear un hueco de
    // dias sin contrato vigente sin que nada avisara.
    'fecha de fin' => [[
        'weekly_hours' => 40,
        'schedule_type' => 'turnos',
        'valid_from' => '2026-01-01',
        'valid_to' => '2026-06-30',
    ]],
])->group('RF-GP-02');

it('responde 404 al registrar el contrato de alguien que no existe', function (): void {
    $contexto = contextoDeContrato();

    Api::as($contexto['token'])
        ->post('/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-000000000000/contracts', [
            'weekly_hours' => 40,
            'schedule_type' => 'turnos',
            'valid_from' => '2026-01-01',
        ])
        ->assertStatus(404);
})->group('RF-GP-02');

it('devuelve una lista vacia para quien no tiene contrato, no un 404', function (): void {
    // No tener contrato registrado es una respuesta, y ademas es la que explica
    // los dias que el informe de RF-IN-03 cuenta como «sin contrato».
    $contexto = contextoDeContrato();

    Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['employee'].'/contracts')
        ->assertValidResponse(200)
        ->assertJsonCount(0, 'data');
})->group('RF-GP-02');

it('deja el cambio de contrato en audit_log con su accion propia', function (): void {
    // Regla dura 6: `weekly_hours` es la cifra contra la que se mide la jornada
    // de una persona. Quien la toca puede convertir cien horas de exceso en cero
    // sin tocar un solo fichaje, y sin traza eso no se puede investigar.
    $contexto = contextoDeContrato();

    Api::as($contexto['token'])
        ->post('/api/v1/employees/'.$contexto['employee'].'/contracts', [
            'weekly_hours' => 20,
            'schedule_type' => 'turnos',
            'valid_from' => '2026-01-01',
        ])
        ->assertValidResponse(201);

    Api::as($contexto['token'])
        ->post('/api/v1/employees/'.$contexto['employee'].'/contracts', [
            'weekly_hours' => 40,
            'schedule_type' => 'turnos',
            'valid_from' => '2026-03-16',
        ])
        ->assertValidResponse(201);

    $asientos = DB::table('audit_log')
        ->where('action', 'employment_contract.registered')
        ->orderBy('id')
        ->get()
        ->all();

    expect($asientos)->toHaveCount(2);

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) ($asientos[1]->payload ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['employee_uuid'] ?? null)->toBe($contexto['employee'])
        // `40.0` y no `40`: el asiento guarda lo pactado como numero con
        // decimales, porque 37,5 h semanales es el caso normal en hosteleria.
        ->and($payload['weekly_hours'] ?? null)->toBe(40.0)
        ->and($payload['valid_from'] ?? null)->toBe('2026-03-16')
        // Lo que convierte el asiento en reconstruible: hasta cuando quedo el
        // anterior. Sin esto no se distingue «se le abrio el primero» de «se le
        // cambio el que tenia».
        ->and($payload['previous_valid_to'] ?? null)->toBe('2026-03-15')
        // Y el ANTES de la cifra: «¿quien le subio las horas y desde cuantas?»
        // se contesta con esta fila y no reconstruyendo la serie de contratos.
        ->and($payload)->toHaveKeys(['previous_weekly_hours', 'previous_annual_hours'])
        ->and($payload['previous_weekly_hours'])->toBe(20.0)
        // Y ningun nombre (regla dura 21).
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('Persona');

    // El PRIMER contrato de la persona no tiene antes, y el asiento lo dice con
    // nulos en lugar de callarse: un `null` explicito distingue «no habia
    // contrato» de «el asiento es de una version que no lo guardaba».
    /** @var array<string, mixed> $primero */
    $primero = json_decode((string) ($asientos[0]->payload ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

    expect($primero)->toHaveKeys(['previous_valid_to', 'previous_weekly_hours', 'previous_annual_hours'])
        ->and($primero['previous_valid_to'])->toBeNull()
        ->and($primero['previous_weekly_hours'])->toBeNull()
        ->and($primero['previous_annual_hours'])->toBeNull();
})->group('RF-GP-02', 'RS-07');
