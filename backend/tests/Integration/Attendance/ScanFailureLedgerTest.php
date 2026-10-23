<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\ScanFailureAttempt;
use App\Modules\Attendance\Domain\ValueObject\ScanDiscardThreshold;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Infrastructure\Adapter\ConfiguredScanDiscardSettings;
use App\Modules\Attendance\Infrastructure\Persistence\DatabaseDiscardedScans;
use App\Modules\Attendance\Infrastructure\Persistence\DatabaseScanFailureLedger;
use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;

/*
 * **La cuenta de fallos y el descarte del servidor contra PostgreSQL** (RN-22,
 * ADR-061; doc 02 §9.5: esquema y restricciones, integracion).
 *
 * - `scan_processing_failures`: la historia cuenta solo el quiosco y la huella
 *   del primer intento; la aplicacion no puede corregir un intento; la purga
 *   tecnica borra lo vencido.
 * - `scan_events` y `discarded_scan_reports`: los `CHECK` nuevos hacen
 *   imposible un descarte con tramo, con acumulado o un descarte del servidor
 *   sin sus intentos.
 * - La revision diaria ve el descarte del servidor aunque su `scan_id` este en
 *   `scan_events`: esa fila dice que NO se registro.
 */

uses(RefreshDatabase::class);

const LIBRO_HUELLA = 'b1b4c6f3a9d2e8f70123456789abcdef0123456789abcdef0123456789abcdef';

function intentoFallido(string $scanId, int $deviceId, string $cuando, ?string $huella = LIBRO_HUELLA, string $clase = LogicException::class): ScanFailureAttempt
{
    return new ScanFailureAttempt(
        scanId: $scanId,
        deviceId: $deviceId,
        origin: ScanOrigin::QR_KIOSK,
        payloadFingerprint: $huella,
        failedAt: new DateTimeImmutable($cuando, new DateTimeZone('UTC')),
        exceptionClass: $clase,
    );
}

it('cuenta los intentos del mismo quiosco y la misma huella que el primero, el actual incluido', function (): void {
    $escenario = AttendanceFixtures::scenario();
    $libro = new DatabaseScanFailureLedger(DB::connection());
    $scanId = Str::uuid7()->toString();

    $primero = $libro->record(intentoFallido($scanId, $escenario['device'], '2026-03-14 07:00:00'));
    $libro->record(intentoFallido($scanId, $escenario['device'], '2026-03-14 07:05:00', clase: TypeError::class));
    $tercero = $libro->record(intentoFallido($scanId, $escenario['device'], '2026-03-14 07:10:00'));

    expect($primero?->history->attempts)->toBe(1)
        ->and($tercero?->history->attempts)->toBe(3)
        ->and($tercero?->history->firstFailedAt->format('c'))->toBe('2026-03-14T07:00:00+00:00')
        ->and($tercero?->exceptionClasses)->toBe([LogicException::class, TypeError::class]);
})->group('RN-22');

it('apunta pero no suma un intento de otro quiosco o con otro contenido', function (): void {
    $escenario = AttendanceFixtures::scenario();
    $otro = AttendanceFixtures::device($escenario['site'], 'Spa');
    $libro = new DatabaseScanFailureLedger(DB::connection());
    $scanId = Str::uuid7()->toString();

    $libro->record(intentoFallido($scanId, $escenario['device'], '2026-03-14 07:00:00'));

    expect($libro->record(intentoFallido($scanId, $otro['id'], '2026-03-14 07:01:00')))->toBeNull()
        ->and($libro->record(intentoFallido($scanId, $escenario['device'], '2026-03-14 07:02:00', huella: str_repeat('0', 64))))->toBeNull()
        ->and($libro->record(intentoFallido($scanId, $escenario['device'], '2026-03-14 07:03:00'))?->history->attempts)->toBe(2)
        // Los cuatro estan apuntados: es la historia del fallo.
        ->and(DB::table('scan_processing_failures')->where('scan_id', $scanId)->count())->toBe(4);
})->group('RN-22', 'RS-03');

it('cuenta una huella nula como la misma huella', function (): void {
    $escenario = AttendanceFixtures::scenario();
    $libro = new DatabaseScanFailureLedger(DB::connection());
    $scanId = Str::uuid7()->toString();

    $libro->record(intentoFallido($scanId, $escenario['device'], '2026-03-14 07:00:00', huella: null));

    expect($libro->record(intentoFallido($scanId, $escenario['device'], '2026-03-14 07:01:00', huella: null))?->history->attempts)->toBe(2);
})->group('RN-22');

it('cuenta solo los descartes del servidor de ese quiosco desde el instante dado', function (): void {
    $escenario = AttendanceFixtures::scenario();
    $otro = AttendanceFixtures::device($escenario['site'], 'Spa');
    $libro = new DatabaseScanFailureLedger(DB::connection());

    $aviso = static fn (int $device, string $recibido, string $origen): bool => DB::table('discarded_scan_reports')->insert([
        'scan_id' => Str::uuid7()->toString(),
        'device_id' => $device,
        'origin' => 'qr_kiosk',
        'occurred_at' => '2026-03-13 05:00:00+00',
        'discarded_at' => $recibido,
        'recorded_at' => $recibido,
        'http_status' => 422,
        'problem_type' => 'urn:kronoqr:problem:scan-discarded',
        'owner_employee_id' => null,
        'attribution' => 'none',
        'credential_issued_at' => null,
        'already_recorded' => false,
        'source' => $origen,
        'failed_attempts' => $origen === 'server' ? 10 : null,
        'first_failed_at' => $origen === 'server' ? '2026-03-13 04:00:00+00' : null,
    ]);

    $aviso($escenario['device'], '2026-03-13 08:00:00+00', 'server');
    $aviso($escenario['device'], '2026-03-14 07:59:59+00', 'server');
    $aviso($escenario['device'], '2026-03-14 08:00:00+00', 'server');
    $aviso($escenario['device'], '2026-03-14 09:00:00+00', 'kiosk');
    $aviso($otro['id'], '2026-03-14 09:00:00+00', 'server');

    // Borde incluido: el de las 08:00:00 justas cuenta, el de un segundo antes no.
    expect($libro->serverDiscardsSince($escenario['device'], new DateTimeImmutable('2026-03-14 08:00:00', new DateTimeZone('UTC'))))->toBe(1)
        ->and($libro->serverDiscardsSince($escenario['device'], new DateTimeImmutable('2026-03-13 08:00:00', new DateTimeZone('UTC'))))->toBe(3);
})->group('RN-22');

it('purga los intentos vencidos y deja los vigentes', function (): void {
    $escenario = AttendanceFixtures::scenario();
    $libro = new DatabaseScanFailureLedger(DB::connection());

    $libro->record(intentoFallido(Str::uuid7()->toString(), $escenario['device'], '2026-02-01 07:00:00'));
    $libro->record(intentoFallido(Str::uuid7()->toString(), $escenario['device'], '2026-03-14 07:00:00'));

    expect($libro->purgeFailedBefore(new DateTimeImmutable('2026-02-12 00:00:00', new DateTimeZone('UTC'))))->toBe(1)
        ->and(DB::table('scan_processing_failures')->count())->toBe(1);
})->group('RN-22', 'RL-11');

it('no deja a la aplicacion corregir un intento, pero si purgarlo', function (): void {
    $role = AuditLogSchema::applicationRole();

    /** @var object{can_update: bool, can_delete: bool, can_insert: bool, can_select: bool} $privilegios */
    $privilegios = DB::selectOne(
        "SELECT has_table_privilege(?, 'scan_processing_failures', 'UPDATE') AS can_update,
                has_table_privilege(?, 'scan_processing_failures', 'DELETE') AS can_delete,
                has_table_privilege(?, 'scan_processing_failures', 'INSERT') AS can_insert,
                has_table_privilege(?, 'scan_processing_failures', 'SELECT') AS can_select",
        [$role, $role, $role, $role],
    );

    expect($privilegios->can_update)->toBeFalse()
        ->and($privilegios->can_delete)->toBeTrue()
        ->and($privilegios->can_insert)->toBeTrue()
        ->and($privilegios->can_select)->toBeTrue();
})->group('RN-22', 'RL-04');

it('rechaza en el esquema un descarte con acumulado', function (): void {
    $escenario = AttendanceFixtures::scenario();

    expect(static fn (): bool => DB::table('scan_events')->insert([
        'scan_id' => Str::uuid7()->toString(),
        'device_id' => $escenario['device'],
        'occurred_at' => '2026-03-14 07:00:00+00',
        'recorded_at' => '2026-03-14 08:00:00+00',
        'origin' => 'qr_kiosk',
        'intent' => 'auto',
        'result' => 'discarded_unprocessable',
        'worked_minutes' => 0,
    ]))->toThrow(QueryException::class, 'scan_events_chk_worked_minutes');
})->group('RN-22', 'RL-04');

it('admite en el esquema el descarte sin tramo y sin acumulado', function (): void {
    $escenario = AttendanceFixtures::scenario();

    DB::table('scan_events')->insert([
        'scan_id' => Str::uuid7()->toString(),
        'device_id' => $escenario['device'],
        'occurred_at' => '2026-03-14 07:00:00+00',
        'recorded_at' => '2026-03-14 08:00:00+00',
        'origin' => 'qr_kiosk',
        'intent' => 'auto',
        'result' => 'discarded_unprocessable',
    ]);

    expect(DB::table('scan_events')->where('result', 'discarded_unprocessable')->count())->toBe(1);
})->group('RN-22');

it('rechaza en el esquema un descarte del servidor sin sus intentos y un aviso del quiosco con ellos', function (array $columnas): void {
    $escenario = AttendanceFixtures::scenario();

    expect(static fn (): bool => DB::table('discarded_scan_reports')->insert([
        'scan_id' => Str::uuid7()->toString(),
        'device_id' => $escenario['device'],
        'origin' => 'qr_kiosk',
        'occurred_at' => '2026-03-14 07:00:00+00',
        'discarded_at' => '2026-03-14 08:00:00+00',
        'recorded_at' => '2026-03-14 08:00:00+00',
        'problem_type' => null,
        'owner_employee_id' => null,
        'attribution' => 'none',
        'credential_issued_at' => null,
        'already_recorded' => false,
        ...$columnas,
    ]))->toThrow(QueryException::class, 'discarded_scan_reports_chk_source');
})->with([
    'servidor sin intentos' => [['http_status' => 422, 'source' => 'server', 'failed_attempts' => null, 'first_failed_at' => '2026-03-14 07:00:00+00']],
    'servidor sin primer fallo' => [['http_status' => 422, 'source' => 'server', 'failed_attempts' => 10, 'first_failed_at' => null]],
    'servidor con un 400' => [['http_status' => 400, 'source' => 'server', 'failed_attempts' => 10, 'first_failed_at' => '2026-03-14 07:00:00+00']],
    'quiosco con intentos' => [['http_status' => 400, 'source' => 'kiosk', 'failed_attempts' => 3, 'first_failed_at' => null]],
    'origen desconocido' => [['http_status' => 400, 'source' => 'tablet', 'failed_attempts' => null, 'first_failed_at' => null]],
])->group('RN-22', 'RL-04');

it('lleva a la revision diaria el descarte del servidor con sus intentos aunque su scan_id este en scan_events', function (): void {
    $escenario = AttendanceFixtures::scenario();
    $empleado = AttendanceFixtures::employeeIdOf($escenario['employee']);
    $scanId = Str::uuid7()->toString();

    DB::table('scan_events')->insert([
        'scan_id' => $scanId,
        'device_id' => $escenario['device'],
        'employee_id' => $empleado,
        'occurred_at' => '2026-03-14 07:00:00+00',
        'recorded_at' => '2026-03-14 08:05:00+00',
        'origin' => 'pin_kiosk',
        'intent' => 'auto',
        'result' => 'discarded_unprocessable',
    ]);
    DB::table('discarded_scan_reports')->insert([
        'scan_id' => $scanId,
        'device_id' => $escenario['device'],
        'origin' => 'pin_kiosk',
        'occurred_at' => '2026-03-14 07:00:00+00',
        'discarded_at' => '2026-03-14 08:05:00+00',
        'recorded_at' => '2026-03-14 08:05:00+00',
        'http_status' => 422,
        'problem_type' => 'urn:kronoqr:problem:scan-discarded',
        'owner_employee_id' => $empleado,
        'attribution' => 'employee_code',
        'credential_issued_at' => null,
        'already_recorded' => false,
        'source' => 'server',
        'failed_attempts' => 12,
        'first_failed_at' => '2026-03-14 07:01:00+00',
    ]);

    $descartes = (new DatabaseDiscardedScans(DB::connection()))->attributedBetween(
        new DateTimeImmutable('2026-03-14 00:00:00', new DateTimeZone('UTC')),
        new DateTimeImmutable('2026-03-15 00:00:00', new DateTimeZone('UTC')),
    );

    expect($descartes)->toHaveCount(1)
        ->and($descartes[0]->scanId)->toBe($scanId)
        ->and($descartes[0]->source())->toBe('server')
        ->and($descartes[0]->serverAttempts)->toBe(12)
        ->and($descartes[0]->problem())->toBe('scan-discarded');
})->group('RN-22', 'RF-PR-01');

it('lee el umbral de la configuracion y no corrige en silencio uno imposible', function (array $valores, ?ScanDiscardThreshold $esperado): void {
    config(['attendance.scan_discard' => $valores]);

    $leer = static fn (): ScanDiscardThreshold => (new ConfiguredScanDiscardSettings)->threshold();

    if ($esperado instanceof ScanDiscardThreshold) {
        expect($leer())->toEqual($esperado);

        return;
    }

    expect($leer)->toThrow(InvalidArgumentException::class);
})->with([
    'de serie' => [['min_attempts' => '10', 'min_minutes' => '60', 'daily_budget' => '3'], new ScanDiscardThreshold(10, 60, 3)],
    'apagado' => [['min_attempts' => '10', 'min_minutes' => '60', 'daily_budget' => '0'], new ScanDiscardThreshold(10, 60, 0)],
    'en los suelos' => [['min_attempts' => ' 3 ', 'min_minutes' => '15', 'daily_budget' => '1'], new ScanDiscardThreshold(3, 15, 1)],
    'dos intentos' => [['min_attempts' => '2', 'min_minutes' => '60', 'daily_budget' => '3'], null],
    'diez minutos' => [['min_attempts' => '10', 'min_minutes' => '10', 'daily_budget' => '3'], null],
    'cupo negativo' => [['min_attempts' => '10', 'min_minutes' => '60', 'daily_budget' => '-1'], null],
    'texto' => [['min_attempts' => 'diez', 'min_minutes' => '60', 'daily_budget' => '3'], null],
    'vacio' => [['min_attempts' => '', 'min_minutes' => '60', 'daily_budget' => '3'], null],
])->group('RN-22');
