<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\ImportFiles;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La comprobacion de una carga de ausencias deja asiento si confirma una baja
 * medica guardada (**RS-05**, RGPD art. 9, enmienda de ADR-037 del 03-11-2026).
 *
 * Una linea `unchanged` confirma el tipo y las fechas exactas de la baja, y una
 * `overlapping_absence` que hay algo en esas fechas. Sin asiento, el modo
 * comprobacion contestaria «cuando estuvo de baja» sin dejar rastro.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * Una persona con una ausencia guardada del 2 al 6 de marzo de 2026, y su
 * codigo de empleado para escribirlo en el fichero.
 *
 * @return array{employee: string, code: string}
 */
function bajaMedicaCargaPersonaCon(string $type): array
{
    $employee = WorkforceFixtures::employee(WorkforceFixtures::site());
    WorkforceFixtures::absence($employee, $type);

    /** @var string|null $code */
    $code = DB::table('employees')->where('uuid', $employee)->value('employee_code');

    return ['employee' => $employee, 'code' => $code ?? ''];
}

/**
 * Los asientos de las bajas medicas confirmadas por una carga.
 *
 * @return list<array<string, mixed>>
 */
function bajaMedicaCargaAsientos(): array
{
    $payloads = DB::table('audit_log')
        ->where('action', AuditAction::PersonalDataAccessed->value)
        ->whereRaw("payload->>'dataset' = 'sick_leave_import_match'")
        ->orderBy('id')
        ->pluck('payload')
        ->map(static function (mixed $raw): array {
            /** @var array<string, mixed> $payload */
            $payload = json_decode(\is_string($raw) ? $raw : '{}', true, 512, JSON_THROW_ON_ERROR);

            return $payload;
        })
        ->all();

    return array_values($payloads);
}

it('deja asiento cuando la comprobacion confirma una baja medica guardada', function (): void {
    $persona = bajaMedicaCargaPersonaCon('sick_leave');

    $respuesta = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->upload('/api/v1/absences/import', ['mode' => 'validate'], ['file' => ImportFiles::csv(ImportFiles::rows(
            ['codigo', 'tipo', 'desde', 'hasta'],
            [[$persona['code'], 'baja', '2026-03-02', '2026-03-06']],
        ))])
        ->assertValidResponse(200)
        ->assertJsonPath('rows.0.outcome', 'unchanged');

    $asientos = bajaMedicaCargaAsientos();

    expect($asientos)->toHaveCount(1)
        ->and($asientos[0]['record_count'] ?? null)->toBe(1)
        ->and($asientos[0]['employee_uuids'] ?? null)->toBe($persona['employee'])
        ->and($asientos[0]['file_sha256'] ?? null)->toBe($respuesta->json('file.sha256'))
        // Ni las fechas: el asiento dice de quien, no cuando.
        ->and((string) json_encode($asientos[0]))->not->toContain('2026-03-02');
})->group('RS-05', 'RF-GP-04', 'RL-15');

it('deja asiento tambien cuando la linea solo pisa la baja guardada', function (): void {
    $persona = bajaMedicaCargaPersonaCon('sick_leave');

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->upload('/api/v1/absences/import', ['mode' => 'validate'], ['file' => ImportFiles::csv(ImportFiles::rows(
            ['codigo', 'tipo', 'desde', 'hasta'],
            [[$persona['code'], 'vacaciones', '2026-03-05', '2026-03-09']],
        ))])
        ->assertValidResponse(200)
        ->assertJsonPath('rows.0.messages.0.code', 'overlapping_absence');

    expect(bajaMedicaCargaAsientos())->toHaveCount(1)
        ->and(bajaMedicaCargaAsientos()[0]['employee_uuids'] ?? null)->toBe($persona['employee']);
})->group('RS-05', 'RF-GP-04');

it('no deja asiento si lo que confirma son unas vacaciones', function (): void {
    $persona = bajaMedicaCargaPersonaCon('vacation');

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->upload('/api/v1/absences/import', ['mode' => 'validate'], ['file' => ImportFiles::csv(ImportFiles::rows(
            ['codigo', 'tipo', 'desde', 'hasta'],
            [[$persona['code'], 'vacaciones', '2026-03-02', '2026-03-06']],
        ))])
        ->assertValidResponse(200)
        ->assertJsonPath('rows.0.outcome', 'unchanged');

    expect(bajaMedicaCargaAsientos())->toBe([]);
})->group('RS-05', 'RF-GP-04');

it('si el asiento no se puede escribir, el informe de la carga no sale', function (): void {
    $persona = bajaMedicaCargaPersonaCon('sick_leave');

    app()->instance(PersonalDataAccessLog::class, new class implements PersonalDataAccessLog
    {
        public function recordDisclosure(string $dataset, int $recordCount, array $context = []): void
        {
            throw new RuntimeException('audit_log no responde');
        }
    });

    $respuesta = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->upload('/api/v1/absences/import', ['mode' => 'validate'], ['file' => ImportFiles::csv(ImportFiles::rows(
            ['codigo', 'tipo', 'desde', 'hasta'],
            [[$persona['code'], 'baja', '2026-03-02', '2026-03-06']],
        ))]);

    expect($respuesta->status())->toBe(500)
        ->and($respuesta->json('rows'))->toBeNull();
})->group('RS-05', 'RF-GP-04');
