<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use App\Modules\Compliance\Domain\Exception\InvalidWorkRecordReconciliationWindow;
use App\Modules\Compliance\Domain\ValueObject\AuditedPurge;
use App\Modules\Compliance\Domain\ValueObject\AuditedShiftEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditTrailEntry;
use App\Modules\Compliance\Domain\ValueObject\RecordedCorrection;
use App\Modules\Compliance\Domain\ValueObject\RecordedShiftEntry;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordAuditContext;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordDiscrepancy;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordDiscrepancyKind;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordPair;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordPurgeBoundary;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationScope;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationWindow;
use App\Modules\Compliance\Domain\WorkRecordReconciliation;
use App\Modules\Reporting\Domain\ValueObject\ComplianceShiftSegment;
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

/** Las dos marcas de un alta manual las firma una persona desde el panel. */
const WORK_RECORD_RECONCILIATION_ALTA = ['clockInSource' => 'manual_admin', 'clockOutSource' => 'manual_admin'];

/**
 * Un tramo de `shift_entries`, cerrado a las 14:00 por el quiosco con tarjeta y
 * sin correcciones, salvo lo que se sobrescriba.
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
        'clockInSource' => 'qr_kiosk',
        'clockOutSource' => 'qr_kiosk',
        'corrections' => [],
        'replacementCorrections' => [],
        ...$cambios,
    ];

    /** @var array{uuid: string, employeeUuid: string, siteId: int, workDate: string, clockedInAt: string, clockedOutAt: string|null, durationMinutes: int|null, status: string, version: int, supersededByUuid: string|null, clockInSource: string, clockOutSource: string|null, corrections: list<RecordedCorrection>, replacementCorrections: list<RecordedCorrection>} $fila */
    return new RecordedShiftEntry(...$fila);
}

/** La fila de `shift_corrections` que escribe la correccion de los asientos de abajo. */
function conciliacionCorreccion(string $accion, string $motivo = 'AJUSTE_ACORDADO_CON_RRHH', int $autor = 7): RecordedCorrection
{
    return new RecordedCorrection($accion, $motivo, $autor);
}

/**
 * El asiento de un fichaje de entrada, como lo escribe `RecordShiftEntryAudit::clockedIn()`.
 *
 * @param  array<string, mixed>  $cambios
 */
function conciliacionEntradaAuditada(array $cambios = [], int $id = 10): AuditTrailEntry
{
    return new AuditTrailEntry($id, 'shift_entry.created', [
        'employee_uuid' => WORK_RECORD_RECONCILIATION_PERSONA,
        'shift_entry_uuid' => WORK_RECORD_RECONCILIATION_TRAMO,
        'site_id' => 1,
        'work_date' => '2026-10-07',
        'clocked_in_at' => WORK_RECORD_RECONCILIATION_ENTRADA,
        'origin' => 'qr_kiosk',
        'action' => 'clock_in',
        ...$cambios,
    ]);
}

/**
 * El asiento de un fichaje de salida, como lo escribe `RecordShiftEntryAudit::clockedOut()`.
 *
 * @param  array<string, mixed>  $cambios
 */
function conciliacionSalidaAuditada(array $cambios = []): AuditTrailEntry
{
    return new AuditTrailEntry(20, 'shift_entry.closed', [
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
    ]);
}

/**
 * Lo que dicen la entrada y la salida del quiosco de un tramo cerrado: el ultimo
 * asiento es la salida, el primero la entrada.
 *
 * @param  array<string, mixed>  $cambios  Sobre la salida.
 */
function conciliacionAsientoDeSalida(array $cambios = []): AuditedShiftEntry
{
    $salida = conciliacionSalidaAuditada($cambios);

    return AuditedShiftEntry::fromTrail($salida, false, conciliacionEntradaAuditada(), $salida);
}

/**
 * El asiento de una correccion, como lo escribe `RecordShiftEntryAudit::corrected()`.
 *
 * @param  array<string, mixed>  $cambios
 */
function conciliacionCorreccionAuditada(string $accion, array $cambios = []): AuditTrailEntry
{
    $auditAction = [
        'created' => 'shift_entry.created',
        'modified' => 'shift_entry.modified',
        'closed' => 'shift_entry.closed',
        'voided' => 'shift_entry.voided',
    ][$accion];

    return new AuditTrailEntry(30, $auditAction, [
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
    ]);
}

/**
 * Lo que dicen los asientos de un tramo cuyo unico asiento —primero y ultimo—
 * es una correccion: un alta manual, o la version que produjo una correccion.
 * Con `$comoSustituida`, el tramo es la version anterior y solo se ve en el
 * ultimo, por `superseded_shift_entry_uuid`.
 *
 * @param  array<string, mixed>  $cambios
 */
function conciliacionAsientoDeCorreccion(string $accion, array $cambios = [], bool $comoSustituida = false): AuditedShiftEntry
{
    $asiento = conciliacionCorreccionAuditada($accion, $cambios);

    return $comoSustituida
        ? AuditedShiftEntry::fromTrail($asiento, true)
        : AuditedShiftEntry::fromTrail($asiento, false, $asiento, $accion === 'voided' ? null : $asiento);
}

/**
 * El limite de purgas: una purga admisible con ese corte —escrita cuatro años
 * despues, con cuatro años de conservacion— y los años sellados.
 *
 * @param  list<int>  $sellados
 */
function conciliacionLimite(?string $corte = null, array $sellados = []): WorkRecordPurgeBoundary
{
    $purgas = [];

    if ($corte !== null) {
        $escrita = new DateTimeImmutable($corte.'T03:00:00Z')->modify('+4 years');
        $purgas[] = new AuditedPurge(90, $escrita, $corte, 4, 1);
    }

    return WorkRecordPurgeBoundary::assess(
        new WorkRecordAuditContext($purgas, $sellados),
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
    );
}

/**
 * @param  list<int>  $sellados
 */
function conciliacionCompara(?RecordedShiftEntry $fila, ?AuditedShiftEntry $asiento, ?string $purgadoHasta = null, array $sellados = []): ?WorkRecordDiscrepancy
{
    return WorkRecordReconciliation::compare(
        new WorkRecordPair(WORK_RECORD_RECONCILIATION_TRAMO, $fila, $asiento),
        conciliacionLimite($purgadoHasta, $sellados),
    );
}

// --- Lo que NO es una discrepancia: el uso normal del producto -------------

it('da por bueno un tramo cerrado por el quiosco que coincide con su asiento', function (): void {
    expect(conciliacionCompara(conciliacionFila(), conciliacionAsientoDeSalida()))->toBeNull();
})->group('RL-04', 'RS-07');

it('da por bueno un tramo abierto contra el asiento de su entrada', function (): void {
    $entrada = AuditedShiftEntry::fromTrail(conciliacionEntradaAuditada(), false, conciliacionEntradaAuditada(), conciliacionEntradaAuditada());

    expect(conciliacionCompara(
        conciliacionFila(['clockedOutAt' => null, 'durationMinutes' => null, 'status' => 'open', 'clockOutSource' => null]),
        $entrada,
    ))->toBeNull();

    // Y el mismo asiento contra un tramo que alguien cerro por SQL: la salida
    // no la escribio ningun fichaje.
    expect(conciliacionCompara(conciliacionFila(), $entrada)?->fields)
        ->toBe(['clocked_out_at', 'duration_minutes', 'clock_out_source', 'status']);
})->group('RL-04', 'RS-07');

it('da por bueno un tramo anomalo cuando su asiento trae anomalias, y no al reves', function (): void {
    $anomalo = conciliacionAsientoDeSalida(['anomalies' => ['shift_too_long']]);

    expect(conciliacionCompara(conciliacionFila(['status' => 'anomalous']), $anomalo))->toBeNull()
        ->and(conciliacionCompara(conciliacionFila(['status' => 'closed']), $anomalo)?->fields)->toBe(['status'])
        ->and(conciliacionCompara(conciliacionFila(['status' => 'anomalous']), conciliacionAsientoDeSalida())?->fields)->toBe(['status']);
})->group('RL-04', 'RN-07');

it('admite los dos estados cerrados si el asiento es de una version que no apuntaba las anomalias', function (): void {
    $antiguo = AuditedShiftEntry::fromTrail(new AuditTrailEntry(20, 'shift_entry.closed', [
        'employee_uuid' => WORK_RECORD_RECONCILIATION_PERSONA,
        'shift_entry_uuid' => WORK_RECORD_RECONCILIATION_TRAMO,
        'site_id' => 1,
        'work_date' => '2026-10-07',
        'clocked_in_at' => WORK_RECORD_RECONCILIATION_ENTRADA,
        'clocked_out_at' => WORK_RECORD_RECONCILIATION_SALIDA,
    ]), false);

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
        'clockOutSource' => 'manual_admin',
        'corrections' => [conciliacionCorreccion('modified')],
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
        'replacementCorrections' => [conciliacionCorreccion('modified')],
    ]);
    $asientoDeLaVieja = conciliacionAsientoDeCorreccion('modified', [
        'shift_entry_uuid' => WORK_RECORD_RECONCILIATION_SUSTITUTA,
        'superseded_shift_entry_uuid' => WORK_RECORD_RECONCILIATION_TRAMO,
        'version' => 2,
        'before' => ['clocked_in_at' => WORK_RECORD_RECONCILIATION_ENTRADA, 'clocked_out_at' => WORK_RECORD_RECONCILIATION_SALIDA],
        'after' => ['clocked_in_at' => WORK_RECORD_RECONCILIATION_ENTRADA, 'clocked_out_at' => '2026-10-07T14:30:00.000000Z'],
    ], true);

    expect(WorkRecordReconciliation::compare(new WorkRecordPair(WORK_RECORD_RECONCILIATION_SUSTITUTA, $nueva, $asientoDeLaNueva), conciliacionLimite()))->toBeNull()
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

    expect(conciliacionCompara(conciliacionFila(['status' => 'voided', 'corrections' => [conciliacionCorreccion('voided')]]), $anulacion))->toBeNull()
        ->and(conciliacionCompara(conciliacionFila(WORK_RECORD_RECONCILIATION_ALTA + ['corrections' => [conciliacionCorreccion('created')]]), conciliacionAsientoDeCorreccion('created')))->toBeNull();
})->group('RL-04', 'RN-13');

it('no confunde un tramo purgado con uno borrado, y solo hasta el corte auditado', function (): void {
    $asiento = conciliacionAsientoDeSalida();

    // La purga borra `work_date < corte`, igual que esta desigualdad.
    expect(conciliacionCompara(null, $asiento, '2026-10-08'))->toBeNull()
        ->and(conciliacionCompara(null, $asiento, '2026-10-07')?->kind)->toBe(WorkRecordDiscrepancyKind::AuditWithoutEntry)
        ->and(conciliacionCompara(null, $asiento)?->kind)->toBe(WorkRecordDiscrepancyKind::AuditWithoutEntry);
})->group('RL-04', 'RL-02');

it('no denuncia el turno de la noche del 31 de diciembre cuyo asiento se fue con la particion de su año (ADR-027)', function (): void {
    $nochevieja = conciliacionFila([
        'workDate' => '2026-01-01',
        'clockedInAt' => '2025-12-31T23:30:00.000000Z',
        'clockedOutAt' => '2026-01-01T07:30:00.000000Z',
    ]);

    expect(conciliacionCompara($nochevieja, null, null, [2025]))->toBeNull()
        ->and(conciliacionCompara($nochevieja, null, null, [2024])?->kind)->toBe(WorkRecordDiscrepancyKind::EntryWithoutAudit);
})->group('RL-04', 'RL-02', 'RS-07');

it('no deja que un año sellado tape un tramo inventado con la entrada en ese año', function (array $cambios): void {
    // La exencion es la de la noche del 31: nada de un `INSERT` con la entrada
    // en 2022 y la jornada de 2026, ni de un tramo corriente de un año sellado.
    expect(conciliacionCompara(conciliacionFila($cambios), null, null, [2022, 2025])?->kind)
        ->toBe(WorkRecordDiscrepancyKind::EntryWithoutAudit);
})->with([
    'entrada vieja y jornada reciente' => [['clockedInAt' => '2022-03-01T06:00:00.000000Z', 'workDate' => '2026-10-07']],
    'tramo corriente de un año sellado' => [['clockedInAt' => '2025-06-10T06:00:00.000000Z', 'workDate' => '2025-06-10']],
    'jornada del 1 de enero de otro año' => [['clockedInAt' => '2022-12-31T23:30:00.000000Z', 'workDate' => '2026-01-01']],
    'dos dias entre entrada y jornada' => [['clockedInAt' => '2025-12-30T23:30:00.000000Z', 'workDate' => '2026-01-01']],
])->group('RL-04', 'RS-07');

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

    expect(conciliacionCompara(conciliacionFila(['status' => 'closed', 'corrections' => [conciliacionCorreccion('voided')]]), $anulacion)?->fields)
        ->toBe(['status']);
})->group('RL-04', 'RN-13');

it('detecta un alta manual sin su fila de correccion y una version cambiada', function (): void {
    expect(conciliacionCompara(conciliacionFila(WORK_RECORD_RECONCILIATION_ALTA), conciliacionAsientoDeCorreccion('created'))?->fields)->toBe(['shift_corrections'])
        ->and(conciliacionCompara(conciliacionFila(WORK_RECORD_RECONCILIATION_ALTA + ['version' => 3, 'corrections' => [conciliacionCorreccion('created')]]), conciliacionAsientoDeCorreccion('created'))?->fields)->toBe(['version']);
})->group('RL-04', 'RN-13');

it('detecta un asiento cuyo tramo ya no existe: un DELETE', function (): void {
    $hallazgo = conciliacionCompara(null, conciliacionAsientoDeSalida());

    expect($hallazgo?->kind)->toBe(WorkRecordDiscrepancyKind::AuditWithoutEntry)
        ->and($hallazgo?->auditEntryId)->toBe(20)
        ->and($hallazgo?->describe())->toBe('shift_entries '.WORK_RECORD_RECONCILIATION_TRAMO.' · audit_without_entry · audit_log #20');
})->group('RL-04', 'RS-07');

it('detecta una entrada atribuida a otro origen: un fichaje por PIN que pasa por tarjeta', function (): void {
    $entradaPorPin = conciliacionEntradaAuditada(['origin' => 'pin_kiosk']);
    $salida = conciliacionSalidaAuditada();
    $asiento = AuditedShiftEntry::fromTrail($salida, false, $entradaPorPin, $salida);

    expect(conciliacionCompara(conciliacionFila(['clockInSource' => 'pin_kiosk']), $asiento))->toBeNull()
        ->and(conciliacionCompara(conciliacionFila(), $asiento)?->fields)->toBe(['clock_in_source'])
        ->and(conciliacionCompara(conciliacionFila(['clockOutSource' => 'manual_admin', 'clockInSource' => 'pin_kiosk']), $asiento)?->fields)->toBe(['clock_out_source']);
})->group('RL-04', 'RS-07', 'RF-AT-11');

it('exige manual_admin en el lado que cambio una correccion, y no mira el que no cambio', function (): void {
    // Cambia solo la salida: la entrada hereda su origen de la version
    // anterior, que este asiento no dice.
    $asiento = conciliacionAsientoDeCorreccion('modified', [
        'version' => 2,
        'before' => ['clocked_in_at' => WORK_RECORD_RECONCILIATION_ENTRADA, 'clocked_out_at' => '2026-10-07T13:00:00.000000Z'],
        'after' => ['clocked_in_at' => WORK_RECORD_RECONCILIATION_ENTRADA, 'clocked_out_at' => WORK_RECORD_RECONCILIATION_SALIDA],
    ]);
    $fila = ['version' => 2, 'corrections' => [conciliacionCorreccion('modified')]];

    expect(conciliacionCompara(conciliacionFila([...$fila, 'clockOutSource' => 'manual_admin']), $asiento))->toBeNull()
        ->and(conciliacionCompara(conciliacionFila([...$fila, 'clockInSource' => 'pin_kiosk', 'clockOutSource' => 'manual_admin']), $asiento))->toBeNull()
        ->and(conciliacionCompara(conciliacionFila($fila), $asiento)?->fields)->toBe(['clock_out_source']);

    // Un alta manual son dos marcas firmadas: las dos `manual_admin`.
    expect(conciliacionCompara(
        conciliacionFila(['clockInSource' => 'qr_kiosk', 'clockOutSource' => 'manual_admin', 'corrections' => [conciliacionCorreccion('created')]]),
        conciliacionAsientoDeCorreccion('created'),
    )?->fields)->toBe(['clock_in_source']);
})->group('RL-04', 'RN-13');

it('exige la fila de correccion con el mismo motivo y el mismo autor que su asiento', function (array $correccion): void {
    $hallazgo = conciliacionCompara(
        conciliacionFila(WORK_RECORD_RECONCILIATION_ALTA + ['corrections' => [conciliacionCorreccion(...$correccion)]]),
        conciliacionAsientoDeCorreccion('created'),
    );

    expect($hallazgo?->kind)->toBe(WorkRecordDiscrepancyKind::EntryDiffersFromAudit)
        ->and($hallazgo?->fields)->toBe(['shift_corrections']);
})->with([
    'otro autor' => [['created', 'AJUSTE_ACORDADO_CON_RRHH', 8]],
    'otro motivo' => [['created', 'OLVIDO_FICHAJE_ENTRADA', 7]],
    'otra accion' => [['modified', 'AJUSTE_ACORDADO_CON_RRHH', 7]],
])->group('RL-04', 'RN-13');

// --- Las purgas: solo cuenta la que la purga real pudo escribir --------------

it('admite el asiento de una purga real y lo usa solo para su centro', function (): void {
    $purga = new AuditedPurge(90, new DateTimeImmutable('2030-10-08T05:00:00Z'), '2026-10-08', 4, 1);
    $limite = WorkRecordPurgeBoundary::assess(new WorkRecordAuditContext([$purga]), new DateTimeImmutable('2031-01-01T00:00:00Z'), 1);
    $deOtroCentro = AuditedShiftEntry::fromTrail(conciliacionSalidaAuditada(['site_id' => 2]), false);

    expect($limite->rejected)->toBe([])
        ->and($limite->cutoffFor(1))->toBe('2026-10-08')
        ->and(WorkRecordReconciliation::compare(new WorkRecordPair(WORK_RECORD_RECONCILIATION_TRAMO, null, conciliacionAsientoDeSalida()), $limite))->toBeNull()
        ->and(WorkRecordReconciliation::compare(new WorkRecordPair(WORK_RECORD_RECONCILIATION_TRAMO, null, $deOtroCentro), $limite)?->kind)
        ->toBe(WorkRecordDiscrepancyKind::AuditWithoutEntry);
})->group('RL-02', 'RL-04');

it('no cree un asiento de purga que la purga real no pudo escribir, y lo denuncia', function (AuditedPurge $purga, array $campos): void {
    $limite = WorkRecordPurgeBoundary::assess(new WorkRecordAuditContext([$purga]), new DateTimeImmutable('2026-10-08T04:15:00Z'), 1);

    expect($limite->cutoffFor(1))->toBeNull()
        ->and($limite->rejected)->toHaveCount(1)
        ->and($limite->rejected[0]->kind)->toBe(WorkRecordDiscrepancyKind::PurgeOutOfBounds)
        ->and($limite->rejected[0]->fields)->toBe($campos)
        ->and($limite->rejected[0]->describe())->toStartWith('audit_log #91 · purge_out_of_bounds')
        // Y el borrado que pretendia tapar sale igual.
        ->and(WorkRecordReconciliation::compare(new WorkRecordPair(WORK_RECORD_RECONCILIATION_TRAMO, null, conciliacionAsientoDeSalida()), $limite)?->kind)
        ->toBe(WorkRecordDiscrepancyKind::AuditWithoutEntry);
})->with([
    'corte en el futuro' => [new AuditedPurge(91, new DateTimeImmutable('2026-10-08T03:00:00Z'), '9999-12-31', 4, 1), ['cutoff_date']],
    'corte de ayer con cuatro años' => [new AuditedPurge(91, new DateTimeImmutable('2026-10-08T03:00:00Z'), '2026-10-08', 4, 1), ['cutoff_date']],
    'corte que no es una fecha' => [new AuditedPurge(91, new DateTimeImmutable('2026-10-08T03:00:00Z'), '2026-13-40', 4, 1), ['cutoff_date']],
    'cero años de conservacion' => [new AuditedPurge(91, new DateTimeImmutable('2026-10-08T03:00:00Z'), '2026-10-08', 0, 1), ['retention_years']],
    'sin años de conservacion' => [new AuditedPurge(91, new DateTimeImmutable('2026-10-08T03:00:00Z'), '2026-10-08', null, 1), ['retention_years']],
    'escrito despues de la pasada' => [new AuditedPurge(91, new DateTimeImmutable('2031-01-01T00:00:00Z'), '2026-10-08', 4, 1), ['occurred_at']],
    'sin centro' => [new AuditedPurge(91, new DateTimeImmutable('2031-01-01T00:00:00Z'), '2026-10-08', 4, null), ['occurred_at', 'site_id']],
])->group('RL-02', 'RL-04', 'RS-07');

it('no deja que el plazo vigente del perfil convierta en sospechosa una purga legitima anterior', function (): void {
    // Purga con cuatro años y despues el perfil sube a cinco: el asiento sigue
    // contando, porque se mide contra sus propios años y contra el suelo.
    $purga = new AuditedPurge(90, new DateTimeImmutable('2030-10-08T05:00:00Z'), '2026-10-08', 4, 1);

    expect(WorkRecordPurgeBoundary::violations($purga, new DateTimeImmutable('2031-01-01T00:00:00Z'), 1))->toBe([])
        ->and(WorkRecordPurgeBoundary::violations($purga, new DateTimeImmutable('2031-01-01T00:00:00Z'), 5))->toBe(['retention_years']);
})->group('RL-02');

// --- Piezas -----------------------------------------------------------------

it('cuenta los minutos con la misma regla que el registro horario', function (string $entrada, string $salida): void {
    // La regla esta repetida a proposito (Compliance no puede importar
    // Attendance): esta prueba es lo que impide que las dos se separen.
    $in = new DateTimeImmutable($entrada);
    $out = new DateTimeImmutable($salida);

    expect(WorkRecordReconciliation::workedMinutes($in, $out))
        ->toBe(new TimeRange($in, $out)->duration()->minutes)
        // Y la tercera copia, la del cumplimiento de `Reporting`.
        ->toBe(new ComplianceShiftSegment('x', $in, $out)->minutes());
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
