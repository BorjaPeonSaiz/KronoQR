<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\RetentionScope;
use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseWorkRecordArchive;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;

/*
 * `discarded_scan_reports` contra PostgreSQL real (RN-22, ADR-047): las
 * invariantes que el esquema declara, los permisos del rol de la aplicacion y
 * la retencion (F7 del dictamen de seguridad del bloque 18).
 */

uses(RefreshDatabase::class);

/**
 * @return array{device: int, employeeId: int}
 */
function avisosFixture(): array
{
    $scenario = AttendanceFixtures::scenario();

    return ['device' => $scenario['device'], 'employeeId' => AttendanceFixtures::employeeIdOf($scenario['employee'])];
}

/**
 * @param  array{device: int, employeeId: int}  $fixture
 * @param  array<string, mixed>  $overrides
 */
function insertarAviso(array $fixture, array $overrides = []): string
{
    $scanId = Str::uuid7()->toString();

    DB::table('discarded_scan_reports')->insert([
        'scan_id' => $scanId,
        'device_id' => $fixture['device'],
        'origin' => 'pin_kiosk',
        'occurred_at' => '2026-08-15 06:59:00+00',
        'discarded_at' => '2026-08-15 07:10:00+00',
        'recorded_at' => '2026-08-15 10:00:00+00',
        'http_status' => 400,
        'problem_type' => null,
        'owner_employee_id' => $fixture['employeeId'],
        'attribution' => 'employee_code',
        'credential_issued_at' => null,
        'already_recorded' => false,
        ...$overrides,
    ]);

    return $scanId;
}

it('admite un solo aviso por scan_id', function (): void {
    $fixture = avisosFixture();
    $scanId = insertarAviso($fixture);

    expect(fn (): string => insertarAviso($fixture, ['scan_id' => $scanId]))
        ->toThrow(QueryException::class, 'discarded_scan_reports_scan_id_unique');
})->group('RN-22', 'RF-AT-07');

it('rechaza en el esquema los estados que el dominio no puede construir', function (array $overrides, string $constraint): void {
    $fixture = avisosFixture();

    expect(fn (): string => insertarAviso($fixture, $overrides))->toThrow(QueryException::class, $constraint);
})->with([
    'un 500 no es un descarte' => [['http_status' => 500], 'discarded_scan_reports_chk_http_status'],
    'un 399 tampoco' => [['http_status' => 399], 'discarded_scan_reports_chk_http_status'],
    'una via inventada' => [['origin' => 'manual_admin', 'attribution' => 'none', 'owner_employee_id' => null], 'discarded_scan_reports_chk_origin'],
    'sin dueño y atribuido' => [['owner_employee_id' => null], 'discarded_scan_reports_chk_attribution'],
    'none con dueño' => [['attribution' => 'none'], 'discarded_scan_reports_chk_attribution'],
    'por tarjeta desde un PIN' => [['attribution' => 'credential', 'credential_issued_at' => '2026-08-14 06:00:00+00'], 'discarded_scan_reports_chk_attribution'],
    'por tarjeta sin la emision' => [['origin' => 'qr_kiosk', 'attribution' => 'credential'], 'discarded_scan_reports_chk_attribution'],
    'por codigo desde una tarjeta' => [['origin' => 'qr_kiosk'], 'discarded_scan_reports_chk_attribution'],
])->group('RN-22');

it('no deja a la aplicacion corregir un aviso, y si purgarlo', function (): void {
    // F7: sin `UPDATE`; `DELETE` se conserva para la purga de RL-02, que la
    // ejecuta el rol de la aplicacion en la transaccion que deja su asiento.
    $role = AuditLogSchema::applicationRole();

    /** @var object{can_update: bool, can_delete: bool, can_insert: bool, can_select: bool} $privilegios */
    $privilegios = DB::selectOne(
        "SELECT has_table_privilege(?, 'discarded_scan_reports', 'UPDATE') AS can_update,
                has_table_privilege(?, 'discarded_scan_reports', 'DELETE') AS can_delete,
                has_table_privilege(?, 'discarded_scan_reports', 'INSERT') AS can_insert,
                has_table_privilege(?, 'discarded_scan_reports', 'SELECT') AS can_select",
        [$role, $role, $role, $role],
    );

    expect($privilegios->can_update)->toBeFalse()
        ->and($privilegios->can_delete)->toBeTrue()
        ->and($privilegios->can_insert)->toBeTrue()
        ->and($privilegios->can_select)->toBeTrue();
})->group('RN-22', 'RL-04');

it('purga los avisos vencidos con el registro de jornada y deja los vigentes', function (): void {
    // F7: la tabla entra en la purga de RL-02 y envejece por `occurred_at`.
    $fixture = avisosFixture();
    $vencido = insertarAviso($fixture, ['occurred_at' => '2021-03-01 06:59:00+00']);
    $vigente = insertarAviso($fixture);

    $archive = new DatabaseWorkRecordArchive(DB::connection());
    $corte = new DateTimeImmutable('2022-01-01 00:00:00', new DateTimeZone('UTC'));

    $inspeccion = collect($archive->inspect($corte))->firstWhere('dataset', 'discarded_scan_reports');

    expect($inspeccion?->rows)->toBe(1)
        ->and($inspeccion?->scope)->toBe(RetentionScope::WorkRecords)
        ->and($inspeccion?->oldest)->toBe('2021-03-01');

    $purga = collect($archive->purge($corte, 100))->firstWhere('dataset', 'discarded_scan_reports');

    expect($purga?->rows)->toBe(1)
        ->and(DB::table('discarded_scan_reports')->where('scan_id', $vencido)->exists())->toBeFalse()
        ->and(DB::table('discarded_scan_reports')->where('scan_id', $vigente)->exists())->toBeTrue();
})->group('RN-22', 'RL-02');

it('purga por la recepcion un aviso y un rechazo sin tramo con un occurred_at futuro', function (): void {
    // N1 del dictamen del bloque 18: `occurred_at` lo pone la tablet. Uno de
    // 2099 con la recepcion vencida se purga igual: envejecen por
    // `LEAST(occurred_at, recorded_at)`.
    $fixture = avisosFixture();
    $aviso = insertarAviso($fixture, ['occurred_at' => '2099-01-01 00:00:00+00', 'recorded_at' => '2021-03-01 10:00:00+00']);
    $rechazo = Str::uuid7()->toString();

    DB::table('scan_events')->insert([
        'scan_id' => $rechazo, 'device_id' => $fixture['device'], 'employee_id' => null,
        'occurred_at' => '2099-01-01 00:00:00+00', 'recorded_at' => '2021-03-01 10:00:00+00',
        'origin' => 'qr_kiosk', 'intent' => 'auto', 'result' => 'rejected_signature',
        'flagged_for_review' => false, 'client_meta' => '{}',
    ]);

    $archive = new DatabaseWorkRecordArchive(DB::connection());
    $corte = new DateTimeImmutable('2022-01-01 00:00:00', new DateTimeZone('UTC'));

    $archive->purge($corte, 100);

    expect(DB::table('discarded_scan_reports')->where('scan_id', $aviso)->exists())->toBeFalse()
        ->and(DB::table('scan_events')->where('scan_id', $rechazo)->exists())->toBeFalse();
})->group('RN-22', 'RL-02');
