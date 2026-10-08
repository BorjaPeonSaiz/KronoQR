<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use App\Modules\Compliance\Domain\Exception\InvalidWorkRecordReconciliationWindow;
use App\Modules\Compliance\Domain\ValueObject\AuditedShiftEntry;
use App\Modules\Compliance\Domain\ValueObject\RecordedShiftEntry;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordDiscrepancy;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordDiscrepancyKind;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordPair;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationScope;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationWindow;
use App\Modules\Compliance\Domain\WorkRecordReconciliation;
use Tests\Support\Time\Instants;

/*
 * La regla de la conciliacion entre el registro horario y su auditoria
 * (ADR-057 §4), sin base de datos.
 *
 * Cada caso es un tramo tal y como esta en `shift_entries` frente al ultimo
 * asiento que la aplicacion escribio sobre el, con los payloads que escribe de
 * verdad `RecordShiftEntryAudit`. Lo que importa tanto como detectar es NO
 * detectar: el uso normal del producto —fichar, corregir, anular, purgar— tiene
 * que dar cero, o la alerta se silencia la primera semana.
 *
 * La prueba con PostgreSQL de verdad, fichando por los casos de uso y atacando
 * con SQL directo, es tests/Integration/Compliance/WorkRecordReconciliationTest.php.
 */

const WORK_RECORD_RECONCILIATION_TRAMO = '0199a1b2-0000-7000-8000-00000000a001';

const WORK_RECORD_RECONCILIATION_SUSTITUTA = '0199a1b2-0000-7000-8000-00000000a002';

const WORK_RECORD_RECONCILIATION_PERSONA = '0199a1b2-0000-7000-8000-00000000e001';

const WORK_RECORD_RECONCILIATION_OTRA_PERSONA = '0199a1b2-0000-7000-8000-00000000e002';

const WORK_RECORD_RECONCILIATION_ENTRADA = '2026-10-07T06:00:00.000000Z';

const WORK_RECORD_RECONCILIATION_SALIDA = '2026-10-07T14:00:00.000000Z';

/**
 * Un tramo de `shift_entries`, cerrado a las 14:00 y sin correcciones, salvo lo
 * que se sobrescriba.
 *
 * @param  array<string, mixed>  $cambios
 */
function conciliacionFila(array $cambios = []): RecordedShiftEntry
{
    $fila = [
        'uuid' => WORK_RECORD_RECONCILIATION_TRAMO,
        'employeeUuid' => WORK_RECORD_RECONCILIATION_PERSONA,
        'siteId' => 1,
        'workDate' => '2026-10-07',
        'clockedInAt' => WORK_RECORD_RECONCILIATION_ENTRADA,
        'clockedOutAt' => WORK_RECORD_RECONCILIATION_SALIDA,
        'durationMinutes' => 480,
        'status' => 'closed',
        'version' => 1,
        'supersededByUuid' => null,
        'correctionActions' => [],
        'replacementCorrectionActions' => [],
        ...$cambios,
    ];

    /** @var array{uuid: string, employeeUuid: string, siteId: int, workDate: string, clockedInAt: string, clockedOutAt: string|null, durationMinutes: int|null, status: string, version: int, supersededByUuid: string|null, correctionActions: list<string>, replacementCorrectionActions: list<string>} $fila */
    return new RecordedShiftEntry(...$fila);
}

/**
 * El asiento de un fichaje de salida, como lo escribe `RecordShiftEntryAudit::clockedOut()`.
 *
 * @param  array<string, mixed>  $cambios
 */
function conciliacionAsientoDeSalida(array $cambios = []): AuditedShiftEntry
{
    return AuditedShiftEntry::fromLatestEntry(20, 'shift_entry.closed', [
        'employee_uuid' => WORK_RECORD_RECONCILIATION_PERSONA,
        'shift_entry_uuid' => WORK_RECORD_RECONCILIATION_TRAMO,
        'site_id' => 1,
        'work_date' => '2026-10-07',
        'clocked_in_at' => WORK_RECORD_RECONCILIATION_ENTRADA,
        'clocked_out_at' => WORK_RECORD_RECONCILIATION_SALIDA,
        'origin' => 'qr_kiosk',
        'action' => 'clock_out',
        'worked_minutes' => 480,
        'daily_total_minutes' => 480,
        'anomalies' => [],
        ...$cambios,
    ], false);
}

/**
 * El asiento de una correccion, como lo escribe `RecordShiftEntryAudit::corrected()`.
 *
 * @param  array<string, mixed>  $cambios
 */
function conciliacionAsientoDeCorreccion(string $accion, array $cambios = [], bool $comoSustituida = false): AuditedShiftEntry
{
    $auditAction = [
        'created' => 'shift_entry.created',
        'modified' => 'shift_entry.modified',
        'closed' => 'shift_entry.closed',
        'voided' => 'shift_entry.voided',
    ][$accion];

    return AuditedShiftEntry::fromLatestEntry(30, $auditAction, [
        'employee_uuid' => WORK_RECORD_RECONCILIATION_PERSONA,
        'shift_entry_uuid' => WORK_RECORD_RECONCILIATION_TRAMO,
        'superseded_shift_entry_uuid' => null,
        'site_id' => 1,
        'work_date' => '2026-10-07',
        'action' => $accion,
        'version' => 1,
        'before' => null,
        'after' => ['clocked_in_at' => WORK_RECORD_RECONCILIATION_ENTRADA, 'clocked_out_at' => WORK_RECORD_RECONCILIATION_SALIDA],
        'reason_code' => 'AJUSTE_ACORDADO_CON_RRHH',
        'performed_by_user_id' => 7,
        'daily_total_minutes' => 480,
        'anomalies' => [],
        ...$cambios,
    ], $comoSustituida);
}

/**
 * @param  list<int>  $sellados
 */
function conciliacionCompara(?RecordedShiftEntry $fila, ?AuditedShiftEntry $asiento, ?string $purgadoHasta = null, array $sellados = []): ?WorkRecordDiscrepancy
{
    return WorkRecordReconciliation::compare(
        new WorkRecordPair(WORK_RECORD_RECONCILIATION_TRAMO, $fila, $asiento, $purgadoHasta, $sellados),
    );
}

// --- Lo que NO es una discrepancia: el uso normal del producto -------------

it('da por bueno un tramo cerrado por el quiosco que coincide con su asiento', function (): void {
    expect(conciliacionCompara(conciliacionFila(), conciliacionAsientoDeSalida()))->toBeNull();
})->group('RL-04', 'RS-07');

it('da por bueno un tramo abierto contra el asiento de su entrada', function (): void {
    $entrada = AuditedShiftEntry::fromLatestEntry(10, 'shift_entry.created', [
        'employee_uuid' => WORK_RECORD_RECONCILIATION_PERSONA,
        'shift_entry_uuid' => WORK_RECORD_RECONCILIATION_TRAMO,
        'site_id' => 1,
        'work_date' => '2026-10-07',
        'clocked_in_at' => WORK_RECORD_RECONCILIATION_ENTRADA,
        'origin' => 'qr_kiosk',
        'action' => 'clock_in',
    ], false);

    expect(conciliacionCompara(
        conciliacionFila(['clockedOutAt' => null, 'durationMinutes' => null, 'status' => 'open']),
        $entrada,
    ))->toBeNull();

    // Y el mismo asiento contra un tramo que alguien cerro por SQL: la salida
    // no la escribio ningun fichaje.
    expect(conciliacionCompara(conciliacionFila(), $entrada)?->fields)
        ->toBe(['clocked_out_at', 'duration_minutes', 'status']);
})->group('RL-04', 'RS-07');

it('da por bueno un tramo anomalo cuando su asiento trae anomalias, y no al reves', function (): void {
    $anomalo = conciliacionAsientoDeSalida(['anomalies' => ['shift_too_long']]);

    expect(conciliacionCompara(conciliacionFila(['status' => 'anomalous']), $anomalo))->toBeNull()
        ->and(conciliacionCompara(conciliacionFila(['status' => 'closed']), $anomalo)?->fields)->toBe(['status'])
        ->and(conciliacionCompara(conciliacionFila(['status' => 'anomalous']), conciliacionAsientoDeSalida())?->fields)->toBe(['status']);
})->group('RL-04', 'RN-07');

it('admite los dos estados cerrados si el asiento es de una version que no apuntaba las anomalias', function (): void {
    $antiguo = AuditedShiftEntry::fromLatestEntry(20, 'shift_entry.closed', [
        'employee_uuid' => WORK_RECORD_RECONCILIATION_PERSONA,
        'shift_entry_uuid' => WORK_RECORD_RECONCILIATION_TRAMO,
        'site_id' => 1,
        'work_date' => '2026-10-07',
        'clocked_in_at' => WORK_RECORD_RECONCILIATION_ENTRADA,
        'clocked_out_at' => WORK_RECORD_RECONCILIATION_SALIDA,
    ], false);

    expect(conciliacionCompara(conciliacionFila(['status' => 'closed']), $antiguo))->toBeNull()
        ->and(conciliacionCompara(conciliacionFila(['status' => 'anomalous']), $antiguo))->toBeNull();
})->group('RL-04');

it('da por buenas la version que produjo una correccion y la que sustituyo', function (): void {
    // La nueva: las marcas de `after`, su version y su fila de correccion.
    $nueva = conciliacionFila([
        'uuid' => WORK_RECORD_RECONCILIATION_SUSTITUTA,
        'clockedOutAt' => '2026-10-07T14:30:00.000000Z',
        'durationMinutes' => 510,
        'version' => 2,
        'correctionActions' => ['modified'],
    ]);
    $asientoDeLaNueva = conciliacionAsientoDeCorreccion('modified', [
        'shift_entry_uuid' => WORK_RECORD_RECONCILIATION_SUSTITUTA,
        'superseded_shift_entry_uuid' => WORK_RECORD_RECONCILIATION_TRAMO,
        'version' => 2,
        'before' => ['clocked_in_at' => WORK_RECORD_RECONCILIATION_ENTRADA, 'clocked_out_at' => WORK_RECORD_RECONCILIATION_SALIDA],
        'after' => ['clocked_in_at' => WORK_RECORD_RECONCILIATION_ENTRADA, 'clocked_out_at' => '2026-10-07T14:30:00.000000Z'],
    ]);

    // La vieja: sus marcas son las de `before`, esta sustituida y la apunta.
    $vieja = conciliacionFila([
        'status' => 'superseded',
        'supersededByUuid' => WORK_RECORD_RECONCILIATION_SUSTITUTA,
        'replacementCorrectionActions' => ['modified'],
    ]);
    $asientoDeLaVieja = conciliacionAsientoDeCorreccion('modified', [
        'shift_entry_uuid' => WORK_RECORD_RECONCILIATION_SUSTITUTA,
        'superseded_shift_entry_uuid' => WORK_RECORD_RECONCILIATION_TRAMO,
        'version' => 2,
        'before' => ['clocked_in_at' => WORK_RECORD_RECONCILIATION_ENTRADA, 'clocked_out_at' => WORK_RECORD_RECONCILIATION_SALIDA],
        'after' => ['clocked_in_at' => WORK_RECORD_RECONCILIATION_ENTRADA, 'clocked_out_at' => '2026-10-07T14:30:00.000000Z'],
    ], true);

    expect(WorkRecordReconciliation::compare(new WorkRecordPair(WORK_RECORD_RECONCILIATION_SUSTITUTA, $nueva, $asientoDeLaNueva)))->toBeNull()
        ->and(conciliacionCompara($vieja, $asientoDeLaVieja))->toBeNull();

    // Los minutos de una version retirada no se comparan: ya no suman en
    // ninguna parte, y una correccion desde el panel de una hora manipulada
    // retira la version mala con los minutos de antes de la manipulacion.
    expect(conciliacionCompara(conciliacionFila([...(array) $vieja, 'durationMinutes' => 999]), $asientoDeLaVieja))->toBeNull();

    // Si alguien desengancha la vieja de su sustituta, sale.
    expect(conciliacionCompara(conciliacionFila([...(array) $vieja, 'supersededByUuid' => null]), $asientoDeLaVieja)?->fields)
        ->toBe(['superseded_by']);
})->group('RL-04', 'RN-13');

it('da por bueno un tramo anulado con su correccion, y por buena un alta manual', function (): void {
    $anulacion = conciliacionAsientoDeCorreccion('voided', [
        'before' => ['clocked_in_at' => WORK_RECORD_RECONCILIATION_ENTRADA, 'clocked_out_at' => WORK_RECORD_RECONCILIATION_SALIDA],
        'after' => null,
    ]);

    expect(conciliacionCompara(conciliacionFila(['status' => 'voided', 'correctionActions' => ['voided']]), $anulacion))->toBeNull()
        ->and(conciliacionCompara(conciliacionFila(['correctionActions' => ['created']]), conciliacionAsientoDeCorreccion('created')))->toBeNull();
})->group('RL-04', 'RN-13');

it('no confunde un tramo purgado con uno borrado, y solo hasta el corte auditado', function (): void {
    $asiento = conciliacionAsientoDeSalida();

    // La purga borra `work_date < corte`, igual que esta desigualdad.
    expect(conciliacionCompara(null, $asiento, '2026-10-08'))->toBeNull()
        ->and(conciliacionCompara(null, $asiento, '2026-10-07')?->kind)->toBe(WorkRecordDiscrepancyKind::AuditWithoutEntry)
        ->and(conciliacionCompara(null, $asiento)?->kind)->toBe(WorkRecordDiscrepancyKind::AuditWithoutEntry);
})->group('RL-04', 'RL-02');

it('no denuncia un tramo cuyo asiento se fue con la particion de su año (ADR-027)', function (): void {
    expect(conciliacionCompara(conciliacionFila(), null, null, [2026]))->toBeNull()
        ->and(conciliacionCompara(conciliacionFila(), null, null, [2025])?->kind)->toBe(WorkRecordDiscrepancyKind::EntryWithoutAudit);
})->group('RL-04', 'RL-02', 'RS-07');

it('compara instantes y no textos: la misma marca en otra zona cuadra', function (): void {
    expect(conciliacionCompara(conciliacionFila(['clockedInAt' => '2026-10-07T08:00:00+02:00']), conciliacionAsientoDeSalida()))
        ->toBeNull();
})->group('RL-04');

// --- Lo que SI es una discrepancia: las cuatro huellas ----------------------

it('detecta un tramo sin ningun asiento: un INSERT inventado', function (): void {
    $hallazgo = conciliacionCompara(conciliacionFila(), null);

    expect($hallazgo?->kind)->toBe(WorkRecordDiscrepancyKind::EntryWithoutAudit)
        ->and($hallazgo?->shiftEntryUuid)->toBe(WORK_RECORD_RECONCILIATION_TRAMO);
})->group('RL-04', 'RS-07');

it('detecta una entrada cambiada, aunque sea un microsegundo', function (): void {
    $hallazgo = conciliacionCompara(conciliacionFila(['clockedInAt' => '2026-10-07T06:00:00.000001Z']), conciliacionAsientoDeSalida());

    expect($hallazgo?->kind)->toBe(WorkRecordDiscrepancyKind::EntryDiffersFromAudit)
        ->and($hallazgo?->fields)->toBe(['clocked_in_at'])
        ->and($hallazgo?->auditEntryId)->toBe(20);
})->group('RL-04', 'RS-07');

it('detecta una salida falsa con sus minutos recalculados para que la fila sea coherente', function (): void {
    $hallazgo = conciliacionCompara(
        conciliacionFila(['clockedOutAt' => '2026-10-07T18:00:00.000000Z', 'durationMinutes' => 720]),
        conciliacionAsientoDeSalida(),
    );

    expect($hallazgo?->fields)->toBe(['clocked_out_at', 'duration_minutes']);
})->group('RL-04', 'RS-07');

it('detecta minutos cambiados sin tocar las marcas, que es lo que suma la exportacion', function (): void {
    expect(conciliacionCompara(conciliacionFila(['durationMinutes' => 600]), conciliacionAsientoDeSalida())?->fields)
        ->toBe(['duration_minutes']);
})->group('RL-04', 'RS-07');

it('detecta un tramo pasado a otra persona, a otro centro o a otra jornada', function (): void {
    expect(conciliacionCompara(conciliacionFila([
        'employeeUuid' => WORK_RECORD_RECONCILIATION_OTRA_PERSONA,
        'siteId' => 2,
        'workDate' => '2026-10-08',
    ]), conciliacionAsientoDeSalida())?->fields)->toBe(['employee_uuid', 'site_id', 'work_date']);
})->group('RL-04', 'RS-07');

it('detecta un tramo anulado por SQL, sin correccion: un borrado para la exportacion', function (string $estado): void {
    $hallazgo = conciliacionCompara(conciliacionFila(['status' => $estado]), conciliacionAsientoDeSalida());

    expect($hallazgo?->kind)->toBe(WorkRecordDiscrepancyKind::RetiredWithoutCorrection)
        ->and($hallazgo?->fields)->toBe(['status']);
})->with(['voided', 'superseded'])->group('RL-04', 'RN-13', 'RS-07');

it('detecta una anulacion cuya fila de correccion ha desaparecido', function (): void {
    $anulacion = conciliacionAsientoDeCorreccion('voided', [
        'before' => ['clocked_in_at' => WORK_RECORD_RECONCILIATION_ENTRADA, 'clocked_out_at' => WORK_RECORD_RECONCILIATION_SALIDA],
        'after' => null,
    ]);
    $hallazgo = conciliacionCompara(conciliacionFila(['status' => 'voided']), $anulacion);

    expect($hallazgo?->kind)->toBe(WorkRecordDiscrepancyKind::RetiredWithoutCorrection)
        ->and($hallazgo?->fields)->toBe(['shift_corrections']);
})->group('RL-04', 'RN-13');

it('detecta un tramo anulado que alguien ha vuelto a poner en vigor', function (): void {
    $anulacion = conciliacionAsientoDeCorreccion('voided', [
        'before' => ['clocked_in_at' => WORK_RECORD_RECONCILIATION_ENTRADA, 'clocked_out_at' => WORK_RECORD_RECONCILIATION_SALIDA],
        'after' => null,
    ]);

    expect(conciliacionCompara(conciliacionFila(['status' => 'closed', 'correctionActions' => ['voided']]), $anulacion)?->fields)
        ->toBe(['status']);
})->group('RL-04', 'RN-13');

it('detecta un alta manual sin su fila de correccion y una version cambiada', function (): void {
    expect(conciliacionCompara(conciliacionFila(), conciliacionAsientoDeCorreccion('created'))?->fields)->toBe(['shift_corrections'])
        ->and(conciliacionCompara(conciliacionFila(['version' => 3, 'correctionActions' => ['created']]), conciliacionAsientoDeCorreccion('created'))?->fields)->toBe(['version']);
})->group('RL-04', 'RN-13');

it('detecta un asiento cuyo tramo ya no existe: un DELETE', function (): void {
    $hallazgo = conciliacionCompara(null, conciliacionAsientoDeSalida());

    expect($hallazgo?->kind)->toBe(WorkRecordDiscrepancyKind::AuditWithoutEntry)
        ->and($hallazgo?->auditEntryId)->toBe(20)
        ->and($hallazgo?->describe())->toBe('shift_entries '.WORK_RECORD_RECONCILIATION_TRAMO.' · audit_without_entry · audit_log #20');
})->group('RL-04', 'RS-07');

// --- Piezas -----------------------------------------------------------------

it('cuenta los minutos con la misma regla que el registro horario', function (string $entrada, string $salida): void {
    // La regla esta repetida a proposito (Compliance no puede importar
    // Attendance): esta prueba es lo que impide que las dos se separen.
    $in = new DateTimeImmutable($entrada);
    $out = new DateTimeImmutable($salida);

    expect(WorkRecordReconciliation::workedMinutes($in, $out))
        ->toBe(new TimeRange($in, $out)->duration()->minutes);
})->with([
    'jornada redonda' => ['2026-10-07T06:00:00Z', '2026-10-07T14:00:00Z'],
    'segundos sueltos' => ['2026-10-07T06:00:59Z', '2026-10-07T14:00:58Z'],
    'microsegundos' => ['2026-10-07T06:00:00.999999Z', '2026-10-07T06:01:00.000001Z'],
    'turno de noche' => ['2026-10-07T20:00:00Z', '2026-10-08T04:30:30Z'],
])->group('RL-04', 'RN-06');

it('abre la ventana diaria con dos dias de margen para los asientos', function (): void {
    $ventana = WorkRecordReconciliationWindow::recent(Instants::utc('2026-10-08 04:15'), 7);

    expect($ventana->scope)->toBe(WorkRecordReconciliationScope::Recent)
        ->and($ventana->fromWorkDate)->toBe('2026-10-01')
        ->and($ventana->auditSince?->format('Y-m-d\TH:i:sP'))->toBe('2026-09-29T00:00:00+00:00')
        ->and(WorkRecordReconciliationWindow::full()->auditSince)->toBeNull()
        ->and(WorkRecordReconciliationWindow::full()->fromWorkDate)->toBeNull();
})->group('RL-04', 'RS-07');

it('rechaza una ventana que no mira ningun dia', function (): void {
    WorkRecordReconciliationWindow::recent(Instants::utc('2026-10-08 04:15'), 0);
})->throws(InvalidWorkRecordReconciliationWindow::class)->group('RL-04');
