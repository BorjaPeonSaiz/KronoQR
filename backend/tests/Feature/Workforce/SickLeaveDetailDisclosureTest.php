<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Abrir el detalle de una baja medica deja asiento (**RS-05**, RGPD art. 9,
 * enmienda de ADR-037 del 03-11-2026, V21-7-SC-2).
 *
 * El asiento del listado dice «miro el cuadro de marzo»; este dice «abrio la
 * baja de esta persona», que es lo que hay que poder contestar ante una queja.
 * Describe el alcance —la ausencia, la persona, cuantas versiones y si salio la
 * nota— y nunca lo divulgado (regla dura 21).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');

    config()->set('identity.two_factor.required_roles', []);
});

/**
 * Los asientos de lectura del detalle de una baja medica, del mas antiguo al
 * mas reciente.
 *
 * @return list<array{actor_type: string, payload: array<string, mixed>, raw: string}>
 */
function bajaMedicaDetalleAsientos(): array
{
    $asientos = DB::table('audit_log')
        ->where('action', AuditAction::PersonalDataAccessed->value)
        ->whereRaw("payload->>'dataset' = 'sick_leave_detail'")
        ->orderBy('id')
        ->get()
        ->map(static function (object $fila): array {
            $raw = (string) ($fila->payload ?? '{}');

            /** @var array<string, mixed> $payload */
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return ['actor_type' => (string) ($fila->actor_type ?? ''), 'payload' => $payload, 'raw' => $raw];
        })
        ->all();

    return array_values($asientos);
}

it('deja asiento al abrir una baja medica, con la ausencia y la persona y sin la nota', function (): void {
    $site = WorkforceFixtures::site();
    $persona = WorkforceFixtures::employee($site);
    $baja = WorkforceFixtures::absence($persona, 'sick_leave', 'Diagnostico que no entra en el trail.');

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->get('/api/v1/absences/'.$baja)
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('absence.note', 'Diagnostico que no entra en el trail.');

    $asientos = bajaMedicaDetalleAsientos();

    expect($asientos)->toHaveCount(1)
        ->and($asientos[0]['actor_type'])->toBe('user')
        ->and($asientos[0]['payload']['record_count'] ?? null)->toBe(1)
        ->and($asientos[0]['payload']['absence_uuid'] ?? null)->toBe($baja)
        ->and($asientos[0]['payload']['employee_uuid'] ?? null)->toBe($persona)
        ->and($asientos[0]['payload']['versions'] ?? null)->toBe(1)
        ->and($asientos[0]['payload']['has_note'] ?? null)->toBeTrue()
        // Ni la nota ni el tipo en texto libre: el conjunto ya dice que es una baja.
        ->and($asientos[0]['raw'])->not->toContain('Diagnostico');
})->group('RS-05', 'RF-GP-04', 'RL-15');

it('no deja asiento al abrir unas vacaciones, como la ficha de un empleado', function (): void {
    $site = WorkforceFixtures::site();
    $vacaciones = WorkforceFixtures::absence(WorkforceFixtures::employee($site), 'vacation');

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->get('/api/v1/absences/'.$vacaciones)
        ->assertValidResponse(200);

    expect(bajaMedicaDetalleAsientos())->toBe([])
        ->and(DB::table('audit_log')->where('action', AuditAction::PersonalDataAccessed->value)->count())->toBe(0);
})->group('RS-05', 'RF-GP-04');

it('deja asiento si la baja ya es otra cosa pero su historial dice que lo fue', function (): void {
    // Una baja corregida a vacaciones sigue enseñando en el historial que fue una
    // baja medica, con su nota: eso es lo que sale por la respuesta.
    $site = WorkforceFixtures::site();
    $persona = WorkforceFixtures::employee($site);
    $baja = WorkforceFixtures::absence($persona, 'sick_leave', 'Nota de la baja.');
    $rrhh = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    /** @var string $vigente */
    $vigente = Api::as($rrhh)
        ->patch('/api/v1/absences/'.$baja, [
            'type' => 'vacation',
            'note' => null,
            'reason' => 'Se registro como baja por error.',
        ])
        ->assertValidResponse(200)
        ->json('uuid');

    Api::as($rrhh)
        ->get('/api/v1/absences/'.$vigente)
        ->assertValidResponse(200)
        ->assertJsonPath('absence.type', 'vacation')
        ->assertJsonPath('absence.note', null)
        ->assertJsonPath('history.0.type', 'sick_leave')
        ->assertJsonPath('history.0.note', 'Nota de la baja.');

    $asientos = bajaMedicaDetalleAsientos();

    expect($asientos)->toHaveCount(1)
        ->and($asientos[0]['payload']['absence_uuid'] ?? null)->toBe($vigente)
        ->and($asientos[0]['payload']['versions'] ?? null)->toBe(2)
        // La nota solo esta en la version anterior, y tambien sale.
        ->and($asientos[0]['payload']['has_note'] ?? null)->toBeTrue();
})->group('RS-05', 'RF-GP-04', 'RN-13');

it('al responsable le deja asiento sin nota, porque la nota no le llega', function (): void {
    $site = WorkforceFixtures::site();
    $jefeDeCocina = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    $cocina = WorkforceFixtures::department($site, 'Cocina');
    Department::query()->whereKey($cocina)->update(['manager_user_id' => $jefeDeCocina->id]);

    $baja = WorkforceFixtures::absence(WorkforceFixtures::employee($site, $cocina), 'sick_leave', 'Texto que no le llega.');

    Api::as(ManagementUsers::tokenFor($jefeDeCocina))
        ->get('/api/v1/absences/'.$baja)
        ->assertValidResponse(200)
        ->assertJsonMissingPath('absence.note');

    $asientos = bajaMedicaDetalleAsientos();

    expect($asientos)->toHaveCount(1)
        ->and($asientos[0]['payload']['has_note'] ?? null)->toBeFalse();
})->group('RS-05', 'RF-ID-03', 'RF-GP-04');

it('una lectura fuera de alcance deja la denegacion y no el acceso', function (): void {
    $site = WorkforceFixtures::site();
    $jefeDeCocina = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    $cocina = WorkforceFixtures::department($site, 'Cocina');
    Department::query()->whereKey($cocina)->update(['manager_user_id' => $jefeDeCocina->id]);

    $recepcion = WorkforceFixtures::department($site, 'Recepcion');
    $ajena = WorkforceFixtures::absence(WorkforceFixtures::employee($site, $recepcion), 'sick_leave');

    Api::as(ManagementUsers::tokenFor($jefeDeCocina))
        ->get('/api/v1/absences/'.$ajena)
        ->assertStatus(403);

    expect(bajaMedicaDetalleAsientos())->toBe([])
        ->and(DB::table('audit_log')->where('action', AuditAction::AccessDenied->value)->count())->toBe(1);
})->group('RS-05', 'RF-ID-03');

it('si el asiento no se puede escribir, la baja no se enseña', function (): void {
    $site = WorkforceFixtures::site();
    $baja = WorkforceFixtures::absence(WorkforceFixtures::employee($site), 'sick_leave', 'No debe salir.');

    app()->instance(PersonalDataAccessLog::class, new class implements PersonalDataAccessLog
    {
        public function recordDisclosure(string $dataset, int $recordCount, array $context = []): void
        {
            throw new RuntimeException('audit_log no responde');
        }
    });

    $respuesta = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->get('/api/v1/absences/'.$baja);

    expect($respuesta->status())->toBe(500)
        ->and((string) $respuesta->getContent())->not->toContain('No debe salir');
})->group('RS-05', 'RF-GP-04');
