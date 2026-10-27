<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use App\Modules\Compliance\Domain\Exception\InvalidWorkRecordReconciliationWindow;
use App\Modules\Compliance\Domain\ValueObject\AuditedPurge;
use App\Modules\Compliance\Domain\ValueObject\AuditedPurgeReview;
use App\Modules\Compliance\Domain\ValueObject\AuditedShiftEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditTrailEntry;
use App\Modules\Compliance\Domain\ValueObject\PurgeFloors;
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
use App\Modules\Shared\Domain\ValueObject\Jurisdiction;
use App\Modules\Shared\Domain\ValueObject\LegalRetentionFloor;
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
    // Como termino (RN-05, 2.3.0): un tramo cerrado termina en salida salvo que
    // la prueba diga otra cosa; uno abierto, en nada.
    $fila['closedBy'] = \array_key_exists('closedBy', $cambios)
        ? $cambios['closedBy']
        : ($fila['clockedOutAt'] === null ? null : 'clock_out');

    /** @var array{uuid: string, employeeUuid: string, siteId: int, workDate: string, clockedInAt: string, clockedOutAt: string|null, durationMinutes: int|null, status: string, version: int, supersededByUuid: string|null, clockInSource: string, clockOutSource: string|null, corrections: list<RecordedCorrection>, replacementCorrections: list<RecordedCorrection>, closedBy: string|null} $fila */
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
        PurgeFloors::of([]),
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
})->group('RS-14', 'RL-04', 'RS-07');

it('da por bueno un tramo abierto contra el asiento de su entrada', function (): void {
    $entrada = AuditedShiftEntry::fromTrail(conciliacionEntradaAuditada(), false, conciliacionEntradaAuditada(), conciliacionEntradaAuditada());

    expect(conciliacionCompara(
        conciliacionFila(['clockedOutAt' => null, 'durationMinutes' => null, 'status' => 'open', 'clockOutSource' => null]),
        $entrada,
    ))->toBeNull();

    // Y el mismo asiento contra un tramo que alguien cerro por SQL: la salida
    // no la escribio ningun fichaje.
    expect(conciliacionCompara(conciliacionFila(), $entrada)?->fields)
        ->toBe(['clocked_out_at', 'duration_minutes', 'clock_out_source', 'closed_by', 'status']);
})->group('RS-14', 'RL-04', 'RS-07');

it('da por bueno un tramo anomalo cuando su asiento trae anomalias, y no al reves', function (): void {
    $anomalo = conciliacionAsientoDeSalida(['anomalies' => ['shift_too_long']]);

    expect(conciliacionCompara(conciliacionFila(['status' => 'anomalous']), $anomalo))->toBeNull()
        ->and(conciliacionCompara(conciliacionFila(['status' => 'closed']), $anomalo)?->fields)->toBe(['status'])
        ->and(conciliacionCompara(conciliacionFila(['status' => 'anomalous']), conciliacionAsientoDeSalida())?->fields)->toBe(['status']);
})->group('RS-14', 'RL-04', 'RN-07');

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
})->group('RS-14', 'RL-04');

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
})->group('RS-14', 'RL-04', 'RN-13');

it('da por bueno un tramo anulado con su correccion, y por buena un alta manual', function (): void {
    $anulacion = conciliacionAsientoDeCorreccion('voided', [
        'before' => ['clocked_in_at' => WORK_RECORD_RECONCILIATION_ENTRADA, 'clocked_out_at' => WORK_RECORD_RECONCILIATION_SALIDA],
        'after' => null,
    ]);

    expect(conciliacionCompara(conciliacionFila(['status' => 'voided', 'corrections' => [conciliacionCorreccion('voided')]]), $anulacion))->toBeNull()
        ->and(conciliacionCompara(conciliacionFila(WORK_RECORD_RECONCILIATION_ALTA + ['corrections' => [conciliacionCorreccion('created')]]), conciliacionAsientoDeCorreccion('created')))->toBeNull();
})->group('RS-14', 'RL-04', 'RN-13');

it('no confunde un tramo purgado con uno borrado, y solo hasta el corte auditado', function (): void {
    $asiento = conciliacionAsientoDeSalida();

    // La purga borra `work_date < corte`, igual que esta desigualdad.
    expect(conciliacionCompara(null, $asiento, '2026-10-08'))->toBeNull()
        ->and(conciliacionCompara(null, $asiento, '2026-10-07')?->kind)->toBe(WorkRecordDiscrepancyKind::AuditWithoutEntry)
        ->and(conciliacionCompara(null, $asiento)?->kind)->toBe(WorkRecordDiscrepancyKind::AuditWithoutEntry);
})->group('RS-14', 'RL-04', 'RL-02');

it('no denuncia el turno de la noche del 31 de diciembre cuyo asiento se fue con la particion de su año (ADR-027)', function (): void {
    $nochevieja = conciliacionFila([
        'workDate' => '2026-01-01',
        'clockedInAt' => '2025-12-31T23:30:00.000000Z',
        'clockedOutAt' => '2026-01-01T07:30:00.000000Z',
    ]);

    expect(conciliacionCompara($nochevieja, null, null, [2025]))->toBeNull()
        ->and(conciliacionCompara($nochevieja, null, null, [2024])?->kind)->toBe(WorkRecordDiscrepancyKind::EntryWithoutAudit);
})->group('RS-14', 'RL-04', 'RL-02', 'RS-07');

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
])->group('RS-14', 'RL-04', 'RS-07');

it('compara instantes y no textos: la misma marca en otra zona cuadra', function (): void {
    expect(conciliacionCompara(conciliacionFila(['clockedInAt' => '2026-10-07T08:00:00+02:00']), conciliacionAsientoDeSalida()))
        ->toBeNull();
})->group('RS-14', 'RL-04');

// --- Lo que SI es una discrepancia: las cuatro huellas ----------------------

it('detecta un tramo sin ningun asiento: un INSERT inventado', function (): void {
    $hallazgo = conciliacionCompara(conciliacionFila(), null);

    expect($hallazgo?->kind)->toBe(WorkRecordDiscrepancyKind::EntryWithoutAudit)
        ->and($hallazgo?->shiftEntryUuid)->toBe(WORK_RECORD_RECONCILIATION_TRAMO);
})->group('RS-14', 'RL-04', 'RS-07');

it('detecta una entrada cambiada, aunque sea un microsegundo', function (): void {
    $hallazgo = conciliacionCompara(conciliacionFila(['clockedInAt' => '2026-10-07T06:00:00.000001Z']), conciliacionAsientoDeSalida());

    expect($hallazgo?->kind)->toBe(WorkRecordDiscrepancyKind::EntryDiffersFromAudit)
        ->and($hallazgo?->fields)->toBe(['clocked_in_at'])
        ->and($hallazgo?->auditEntryId)->toBe(20);
})->group('RS-14', 'RL-04', 'RS-07');

it('detecta una salida falsa con sus minutos recalculados para que la fila sea coherente', function (): void {
    $hallazgo = conciliacionCompara(
        conciliacionFila(['clockedOutAt' => '2026-10-07T18:00:00.000000Z', 'durationMinutes' => 720]),
        conciliacionAsientoDeSalida(),
    );

    expect($hallazgo?->fields)->toBe(['clocked_out_at', 'duration_minutes']);
})->group('RS-14', 'RL-04', 'RS-07');

it('detecta minutos cambiados sin tocar las marcas, que es lo que suma la exportacion', function (): void {
    expect(conciliacionCompara(conciliacionFila(['durationMinutes' => 600]), conciliacionAsientoDeSalida())?->fields)
        ->toBe(['duration_minutes']);
})->group('RS-14', 'RL-04', 'RS-07');

it('detecta un tramo pasado a otra persona, a otro centro o a otra jornada', function (): void {
    expect(conciliacionCompara(conciliacionFila([
        'employeeUuid' => WORK_RECORD_RECONCILIATION_OTRA_PERSONA,
        'siteId' => 2,
        'workDate' => '2026-10-08',
    ]), conciliacionAsientoDeSalida())?->fields)->toBe(['employee_uuid', 'site_id', 'work_date']);
})->group('RS-14', 'RL-04', 'RS-07');

it('detecta un tramo anulado por SQL, sin correccion: un borrado para la exportacion', function (string $estado): void {
    $hallazgo = conciliacionCompara(conciliacionFila(['status' => $estado]), conciliacionAsientoDeSalida());

    expect($hallazgo?->kind)->toBe(WorkRecordDiscrepancyKind::RetiredWithoutCorrection)
        ->and($hallazgo?->fields)->toBe(['status']);
})->with(['voided', 'superseded'])->group('RS-14', 'RL-04', 'RN-13', 'RS-07');

it('detecta una anulacion cuya fila de correccion ha desaparecido', function (): void {
    $anulacion = conciliacionAsientoDeCorreccion('voided', [
        'before' => ['clocked_in_at' => WORK_RECORD_RECONCILIATION_ENTRADA, 'clocked_out_at' => WORK_RECORD_RECONCILIATION_SALIDA],
        'after' => null,
    ]);
    $hallazgo = conciliacionCompara(conciliacionFila(['status' => 'voided']), $anulacion);

    expect($hallazgo?->kind)->toBe(WorkRecordDiscrepancyKind::RetiredWithoutCorrection)
        ->and($hallazgo?->fields)->toBe(['shift_corrections']);
})->group('RS-14', 'RL-04', 'RN-13');

it('detecta un tramo anulado que alguien ha vuelto a poner en vigor', function (): void {
    $anulacion = conciliacionAsientoDeCorreccion('voided', [
        'before' => ['clocked_in_at' => WORK_RECORD_RECONCILIATION_ENTRADA, 'clocked_out_at' => WORK_RECORD_RECONCILIATION_SALIDA],
        'after' => null,
    ]);

    expect(conciliacionCompara(conciliacionFila(['status' => 'closed', 'corrections' => [conciliacionCorreccion('voided')]]), $anulacion)?->fields)
        ->toBe(['status']);
})->group('RS-14', 'RL-04', 'RN-13');

it('detecta un alta manual sin su fila de correccion y una version cambiada', function (): void {
    expect(conciliacionCompara(conciliacionFila(WORK_RECORD_RECONCILIATION_ALTA), conciliacionAsientoDeCorreccion('created'))?->fields)->toBe(['shift_corrections'])
        ->and(conciliacionCompara(conciliacionFila(WORK_RECORD_RECONCILIATION_ALTA + ['version' => 3, 'corrections' => [conciliacionCorreccion('created')]]), conciliacionAsientoDeCorreccion('created'))?->fields)->toBe(['version']);
})->group('RS-14', 'RL-04', 'RN-13');

it('detecta un asiento cuyo tramo ya no existe: un DELETE', function (): void {
    $hallazgo = conciliacionCompara(null, conciliacionAsientoDeSalida());

    expect($hallazgo?->kind)->toBe(WorkRecordDiscrepancyKind::AuditWithoutEntry)
        ->and($hallazgo?->auditEntryId)->toBe(20)
        ->and($hallazgo?->describe())->toBe('shift_entries '.WORK_RECORD_RECONCILIATION_TRAMO.' · audit_without_entry · audit_log #20');
})->group('RS-14', 'RL-04', 'RS-07');

it('detecta una entrada atribuida a otro origen: un fichaje por PIN que pasa por tarjeta', function (): void {
    $entradaPorPin = conciliacionEntradaAuditada(['origin' => 'pin_kiosk']);
    $salida = conciliacionSalidaAuditada();
    $asiento = AuditedShiftEntry::fromTrail($salida, false, $entradaPorPin, $salida);

    expect(conciliacionCompara(conciliacionFila(['clockInSource' => 'pin_kiosk']), $asiento))->toBeNull()
        ->and(conciliacionCompara(conciliacionFila(), $asiento)?->fields)->toBe(['clock_in_source'])
        ->and(conciliacionCompara(conciliacionFila(['clockOutSource' => 'manual_admin', 'clockInSource' => 'pin_kiosk']), $asiento)?->fields)->toBe(['clock_out_source']);
})->group('RS-14', 'RL-04', 'RS-07', 'RF-AT-11');

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
})->group('RS-14', 'RL-04', 'RN-13');

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
])->group('RS-14', 'RL-04', 'RN-13');

// --- Las purgas: solo cuenta la que la purga real pudo escribir --------------

it('admite el asiento de una purga real y lo usa solo para su centro', function (): void {
    $purga = new AuditedPurge(90, new DateTimeImmutable('2030-10-08T05:00:00Z'), '2026-10-08', 4, 1);
    $limite = WorkRecordPurgeBoundary::assess(new WorkRecordAuditContext([$purga]), new DateTimeImmutable('2031-01-01T00:00:00Z'), PurgeFloors::of([]));
    $deOtroCentro = AuditedShiftEntry::fromTrail(conciliacionSalidaAuditada(['site_id' => 2]), false);

    expect($limite->rejected)->toBe([])
        ->and($limite->cutoffFor(1))->toBe('2026-10-08')
        ->and(WorkRecordReconciliation::compare(new WorkRecordPair(WORK_RECORD_RECONCILIATION_TRAMO, null, conciliacionAsientoDeSalida()), $limite))->toBeNull()
        ->and(WorkRecordReconciliation::compare(new WorkRecordPair(WORK_RECORD_RECONCILIATION_TRAMO, null, $deOtroCentro), $limite)?->kind)
        ->toBe(WorkRecordDiscrepancyKind::AuditWithoutEntry);
})->group('RS-14', 'RL-02', 'RL-04');

it('no cree un asiento de purga que la purga real no pudo escribir, y lo denuncia', function (AuditedPurge $purga, array $campos): void {
    $limite = WorkRecordPurgeBoundary::assess(new WorkRecordAuditContext([$purga]), new DateTimeImmutable('2026-10-08T04:15:00Z'), PurgeFloors::of([]));

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
])->group('RS-14', 'RL-02', 'RL-04', 'RS-07');

it('no deja que el plazo vigente del perfil convierta en sospechosa una purga legitima anterior', function (): void {
    // Purga con cuatro años y despues el perfil sube a cinco: el asiento sigue
    // contando, porque se mide contra sus propios años y contra el suelo.
    $purga = new AuditedPurge(90, new DateTimeImmutable('2030-10-08T05:00:00Z'), '2026-10-08', 4, 1);

    expect(WorkRecordPurgeBoundary::violations($purga, new DateTimeImmutable('2031-01-01T00:00:00Z'), 1))->toBe([])
        ->and(WorkRecordPurgeBoundary::violations($purga, new DateTimeImmutable('2031-01-01T00:00:00Z'), 5))->toBe(['retention_years']);
})->group('RS-14', 'RL-02');

// --- Piezas -----------------------------------------------------------------

it('cuenta los minutos del asiento con la regla unica del registro', function (string $salida, int $minutos): void {
    // Ya no hay copia que atar: la conciliacion, `TimeRange` y
    // `ComplianceShiftEntry` usan `ElapsedMinutes` (bloque 19, y la guarda
    // `SingleMinuteRuleTest`). Lo que se fija aqui es que la conciliacion la
    // aplica: los minutos que el registro escribio coinciden, y uno mas no.
    $fila = ['clockedOutAt' => $salida];
    $asiento = conciliacionAsientoDeSalida(['clocked_out_at' => $salida]);

    expect(conciliacionCompara(conciliacionFila([...$fila, 'durationMinutes' => $minutos]), $asiento))->toBeNull()
        ->and(conciliacionCompara(conciliacionFila([...$fila, 'durationMinutes' => $minutos + 1]), $asiento)?->fields)
        ->toBe(['duration_minutes'])
        // Y es la misma cifra que da el registro horario.
        ->and(new TimeRange(new DateTimeImmutable(WORK_RECORD_RECONCILIATION_ENTRADA), new DateTimeImmutable($salida))->duration()->minutes)
        ->toBe($minutos);
})->with([
    'jornada redonda' => ['2026-10-07T14:00:00.000000Z', 480],
    'segundos sueltos' => ['2026-10-07T14:00:59.000000Z', 480],
    'microsegundos' => ['2026-10-07T14:00:59.999999Z', 480],
    'turno de noche' => ['2026-10-08T04:30:30.000000Z', 1350],
])->group('RS-14', 'RL-04', 'RN-06', 'RN-09');

it('un asiento con la salida antes que la entrada no tiene minutos con los que coincidir', function (): void {
    // Imposible desde el registro (RN-03 y el CHECK de `shift_entries`): solo
    // lo produce un asiento manipulado, y ahi la duracion tambien discrepa.
    $salida = '2026-10-07T05:59:30.000000Z';

    expect(conciliacionCompara(
        conciliacionFila(['clockedOutAt' => $salida, 'durationMinutes' => 0]),
        conciliacionAsientoDeSalida(['clocked_out_at' => $salida]),
    )?->fields)->toBe(['duration_minutes']);
})->group('RS-14', 'RL-04', 'RN-09');

it('abre la ventana diaria con dos dias de margen para los asientos', function (): void {
    $ventana = WorkRecordReconciliationWindow::recent(Instants::utc('2026-10-08 04:15'), 7);

    expect($ventana->scope)->toBe(WorkRecordReconciliationScope::Recent)
        ->and($ventana->fromWorkDate)->toBe('2026-10-01')
        ->and($ventana->auditSince?->format('Y-m-d\TH:i:sP'))->toBe('2026-09-29T00:00:00+00:00')
        ->and(WorkRecordReconciliationWindow::full()->auditSince)->toBeNull()
        ->and(WorkRecordReconciliationWindow::full()->fromWorkDate)->toBeNull();
})->group('RS-14', 'RL-04', 'RS-07');

it('rechaza una ventana que no mira ningun dia', function (): void {
    WorkRecordReconciliationWindow::recent(Instants::utc('2026-10-08 04:15'), 0);
})->throws(InvalidWorkRecordReconciliationWindow::class)->group('RS-14', 'RL-04');

// --- El suelo legal por jurisdiccion y la purga revisada (bloque 5 de la 2.2.1)

/** Pasada de referencia de las pruebas del suelo y de la revision. */
const WORK_RECORD_RECONCILIATION_PASADA = '2031-01-01T00:00:00Z';

/**
 * Una purga del centro 1 escrita el 8 de octubre de 2029 con tres años de
 * conservacion y el corte que esos tres años permitian: admisible con suelo
 * 1, no admisible en España (suelo 4) solo por el plazo.
 */
function conciliacionPurgaATresAnos(int $id = 90, string $corte = '2026-10-08', ?int $centro = 1, ?int $sueloDeclarado = null): AuditedPurge
{
    return new AuditedPurge($id, new DateTimeImmutable('2029-10-08T05:00:00Z'), $corte, 3, $centro, $sueloDeclarado);
}

/**
 * @param  list<AuditedPurge>  $purgas
 * @param  list<AuditedPurgeReview>  $revisiones
 * @param  array<int, int>  $suelos  Años por centro.
 */
function conciliacionLimiteConSuelo(array $purgas, array $revisiones = [], array $suelos = [1 => 4]): WorkRecordPurgeBoundary
{
    $porCentro = [];

    foreach ($suelos as $centro => $anos) {
        $porCentro[$centro] = LegalRetentionFloor::fromCatalog(Jurisdiction::fromCode('ES'), $anos);
    }

    return WorkRecordPurgeBoundary::assess(
        new WorkRecordAuditContext($purgas, [], $revisiones),
        new DateTimeImmutable(WORK_RECORD_RECONCILIATION_PASADA),
        PurgeFloors::of($porCentro),
    );
}

function conciliacionRevision(int $id, ?int $revisada): AuditedPurgeReview
{
    return new AuditedPurgeReview($id, new DateTimeImmutable('2030-03-01T10:00:00Z'), $revisada);
}

/** Si el borrado del tramo de esa jornada queda explicado por el limite. */
function conciliacionBorradoExplicado(WorkRecordPurgeBoundary $limite, string $jornada = '2026-10-07'): bool
{
    $asiento = AuditedShiftEntry::fromTrail(conciliacionSalidaAuditada(['work_date' => $jornada]), false);

    return WorkRecordReconciliation::compare(new WorkRecordPair(WORK_RECORD_RECONCILIATION_TRAMO, null, $asiento), $limite) === null;
}

it('denuncia una purga española a tres años y admite la de cuatro', function (): void {
    $aTres = conciliacionLimiteConSuelo([conciliacionPurgaATresAnos()]);
    $aCuatro = conciliacionLimiteConSuelo([
        new AuditedPurge(90, new DateTimeImmutable('2030-10-08T05:00:00Z'), '2026-10-08', 4, 1),
    ]);

    expect($aTres->rejected)->toHaveCount(1)
        ->and($aTres->rejected[0]->kind)->toBe(WorkRecordDiscrepancyKind::PurgeOutOfBounds)
        ->and($aTres->rejected[0]->fields)->toBe(['retention_years'])
        ->and($aTres->cutoffFor(1))->toBeNull()
        ->and($aTres->reviewedPurgeIds)->toBe([])
        ->and(conciliacionBorradoExplicado($aTres))->toBeFalse()
        ->and($aCuatro->rejected)->toBe([])
        ->and($aCuatro->cutoffFor(1))->toBe('2026-10-08')
        ->and(conciliacionBorradoExplicado($aCuatro))->toBeTrue();
})->group('RS-14', 'RS-07', 'RL-02', 'RF-PD-07');

it('juzga con el mayor de los suelos: el declarado por el asiento solo lo endurece', function (): void {
    // Centro sin suelo (1) y asiento que declara 4: gana el 4.
    $declaradoMayor = conciliacionLimiteConSuelo([conciliacionPurgaATresAnos(sueloDeclarado: 4)], [], [1 => 1]);
    // Centro español (4) y asiento que declara 1: no se rebaja.
    $declaradoMenor = conciliacionLimiteConSuelo([conciliacionPurgaATresAnos(sueloDeclarado: 1)], [], [1 => 4]);
    // Centro sin suelo y asiento sin declaracion: la purga a tres años vale.
    $sinSuelo = conciliacionLimiteConSuelo([conciliacionPurgaATresAnos()], [], [1 => 1]);

    expect($declaradoMayor->rejected[0]->fields ?? null)->toBe(['retention_years'])
        ->and($declaradoMenor->rejected[0]->fields ?? null)->toBe(['retention_years'])
        ->and($sinSuelo->rejected)->toBe([])
        ->and($sinSuelo->cutoffFor(1))->toBe('2026-10-08');
})->group('RS-14', 'RS-07', 'RL-02');

it('saca de las pendientes una purga revisada y vuelve a explicar sus borrados hasta su corte, no despues', function (): void {
    $limite = conciliacionLimiteConSuelo([conciliacionPurgaATresAnos()], [conciliacionRevision(95, 90)]);

    expect($limite->rejected)->toBe([])
        ->and($limite->reviewedPurgeIds)->toBe([90])
        ->and($limite->explainingReviewedPurgeIds)->toBe([90])
        ->and($limite->cutoffFor(1))->toBe('2026-10-08')
        // La jornada anterior al corte: explicada. La del corte y la posterior, no.
        ->and(conciliacionBorradoExplicado($limite, '2026-10-07'))->toBeTrue()
        ->and(conciliacionBorradoExplicado($limite, '2026-10-08'))->toBeFalse()
        ->and(conciliacionBorradoExplicado($limite, '2026-10-09'))->toBeFalse();
})->group('RS-14', 'RS-07', 'RL-02', 'RL-04');

it('se queda con el corte mas reciente de un centro aunque venga de una purga revisada', function (): void {
    $limite = conciliacionLimiteConSuelo(
        [
            new AuditedPurge(80, new DateTimeImmutable('2028-10-08T05:00:00Z'), '2024-10-08', 4, 1),
            conciliacionPurgaATresAnos(),
            new AuditedPurge(99, new DateTimeImmutable('2028-10-08T05:00:00Z'), '2023-10-08', 4, 1),
        ],
        [conciliacionRevision(95, 90)],
    );

    expect($limite->cutoffFor(1))->toBe('2026-10-08')
        ->and($limite->rejected)->toBe([]);
})->group('RS-14', 'RS-07', 'RL-04');

it('no deja que una revision explique un corte imposible ni una purga sin centro', function (AuditedPurge $purga): void {
    $limite = conciliacionLimiteConSuelo([$purga], [conciliacionRevision(95, 90)]);

    expect($limite->rejected)->toBe([])
        ->and($limite->reviewedPurgeIds)->toBe([90])
        ->and($limite->explainingReviewedPurgeIds)->toBe([])
        ->and($limite->cutoffBySite)->toBe([])
        ->and(conciliacionBorradoExplicado($limite))->toBeFalse();
})->with([
    // Tres años permitian hasta el 8 de octubre de 2026; un dia mas ya no.
    'corte posterior al que su plazo permitia' => [conciliacionPurgaATresAnos(corte: '2026-10-09')],
    'corte imposible a tres años' => [conciliacionPurgaATresAnos(corte: '9999-12-31')],
    'corte que no es una fecha' => [conciliacionPurgaATresAnos(corte: '2026-13-40')],
    'sin centro' => [conciliacionPurgaATresAnos(centro: null)],
])->group('RS-14', 'RS-07', 'RL-04');

it('ignora una revision anterior a la purga o que apunta a otro asiento', function (AuditedPurgeReview $revision): void {
    $limite = conciliacionLimiteConSuelo([conciliacionPurgaATresAnos()], [$revision]);

    expect($limite->rejected)->toHaveCount(1)
        ->and($limite->reviewedPurgeIds)->toBe([])
        ->and($limite->cutoffFor(1))->toBeNull();
})->with([
    'anterior en la cadena' => [conciliacionRevision(89, 90)],
    'la misma fila' => [conciliacionRevision(90, 90)],
    'de otro asiento' => [conciliacionRevision(95, 77)],
    'sin asiento revisado' => [conciliacionRevision(95, null)],
])->group('RS-14', 'RS-07', 'RL-04');

it('cuenta una sola vez una purga revisada dos veces, y sigue contando la posterior sin revisar', function (): void {
    $limite = conciliacionLimiteConSuelo(
        [conciliacionPurgaATresAnos(90), conciliacionPurgaATresAnos(100, '2025-10-08')],
        [conciliacionRevision(95, 90), conciliacionRevision(96, 90)],
    );

    expect($limite->reviewedPurgeIds)->toBe([90])
        ->and($limite->rejected)->toHaveCount(1)
        ->and($limite->rejected[0]->auditEntryId)->toBe(100);
})->group('RS-14', 'RS-07', 'RL-04');

it('explica tras revisarse solo la purga cuyo unico defecto es el plazo', function (AuditedPurge $purga, array $defectos, bool $explica): void {
    // Los defectos son los que da la regla con el suelo español: el corte se
    // juzga contra el plazo declarado aunque este bajo el suelo.
    $calculados = WorkRecordPurgeBoundary::violations($purga, new DateTimeImmutable(WORK_RECORD_RECONCILIATION_PASADA), 4);

    expect($calculados)->toBe($defectos)
        ->and(WorkRecordPurgeBoundary::explainsOnceReviewed($purga, $calculados))->toBe($explica);
})->with([
    'solo el plazo, corte justo' => [conciliacionPurgaATresAnos(), ['retention_years'], true],
    'solo el plazo, corte anterior' => [conciliacionPurgaATresAnos(corte: '2020-01-01'), ['retention_years'], true],
    'plazo y corte imposible' => [conciliacionPurgaATresAnos(corte: '9999-12-31'), ['cutoff_date', 'retention_years'], false],
    'sin defectos' => [new AuditedPurge(90, new DateTimeImmutable('2030-10-08T05:00:00Z'), '2026-10-08', 4, 1), [], false],
    'otro defecto' => [new AuditedPurge(90, new DateTimeImmutable('2032-01-01T05:00:00Z'), '2028-01-01', 3, 1), ['retention_years', 'occurred_at'], false],
    'cero años' => [new AuditedPurge(90, new DateTimeImmutable('2029-10-08T05:00:00Z'), '2029-10-08', 0, 1), ['retention_years'], false],
    'años negativos' => [new AuditedPurge(90, new DateTimeImmutable('2029-10-08T05:00:00Z'), '2029-10-08', -3, 1), ['retention_years'], false],
    'sin años' => [new AuditedPurge(90, new DateTimeImmutable('2029-10-08T05:00:00Z'), '2026-10-08', null, 1), ['retention_years'], false],
    'un año, corte justo' => [new AuditedPurge(90, new DateTimeImmutable('2029-10-08T05:00:00Z'), '2028-10-08', 1, 1), ['retention_years'], true],
    'un año, corte un dia tarde' => [new AuditedPurge(90, new DateTimeImmutable('2029-10-08T05:00:00Z'), '2028-10-09', 1, 1), ['cutoff_date', 'retention_years'], false],
    'sin centro' => [conciliacionPurgaATresAnos(centro: null), ['retention_years', 'site_id'], false],
    'corte un dia tarde' => [conciliacionPurgaATresAnos(corte: '2026-10-09'), ['cutoff_date', 'retention_years'], false],
    'corte invalido' => [conciliacionPurgaATresAnos(corte: '2026-02-30'), ['cutoff_date', 'retention_years'], false],
])->group('RS-14', 'RS-07', 'RL-04');

it('resuelve el suelo de cada centro y el efectivo de cada asiento', function (): void {
    $suelos = PurgeFloors::of([1 => LegalRetentionFloor::fromCatalog(Jurisdiction::fromCode('ES'), 4)]);

    expect($suelos->yearsFor(1))->toBe(4)
        ->and($suelos->yearsFor(2))->toBe(1)
        ->and($suelos->yearsFor(null))->toBe(1)
        ->and($suelos->effectiveFor(conciliacionPurgaATresAnos()))->toBe(4)
        ->and($suelos->effectiveFor(conciliacionPurgaATresAnos(sueloDeclarado: 6)))->toBe(6)
        ->and($suelos->effectiveFor(conciliacionPurgaATresAnos(sueloDeclarado: 2)))->toBe(4)
        ->and($suelos->effectiveFor(conciliacionPurgaATresAnos(centro: 2, sueloDeclarado: 0)))->toBe(1)
        ->and($suelos->effectiveFor(conciliacionPurgaATresAnos(centro: null)))->toBe(1);

    // Un asiento sin centro se juzga con el suelo del centro de la instalacion.
    $conInstalacion = PurgeFloors::of([], LegalRetentionFloor::fromCatalog(Jurisdiction::fromCode('ES'), 4));

    expect($conInstalacion->yearsFor(null))->toBe(4)
        ->and($conInstalacion->yearsFor(3))->toBe(1)
        ->and($conInstalacion->effectiveFor(conciliacionPurgaATresAnos(centro: null)))->toBe(4);
})->group('RS-14', 'RL-02');

it('lee del asiento el suelo declarado y el asiento revisado solo si son enteros', function (): void {
    $instante = new DateTimeImmutable('2030-01-01T00:00:00Z');

    expect(AuditedPurge::fromEntry(1, $instante, ['retention_floor_years' => 4])->declaredFloorYears)->toBe(4)
        ->and(AuditedPurge::fromEntry(1, $instante, ['retention_floor_years' => '4'])->declaredFloorYears)->toBeNull()
        ->and(AuditedPurge::fromEntry(1, $instante, [])->declaredFloorYears)->toBeNull()
        ->and(AuditedPurgeReview::fromEntry(5, $instante, ['reviewed_audit_entry_id' => 1])->reviewedAuditEntryId)->toBe(1)
        ->and(AuditedPurgeReview::fromEntry(5, $instante, ['reviewed_audit_entry_id' => '1'])->reviewedAuditEntryId)->toBeNull()
        ->and(AuditedPurgeReview::fromEntry(5, $instante, [])->reviewedAuditEntryId)->toBeNull()
        ->and(AuditedPurgeReview::fromEntry(5, $instante, [])->occurredAt)->toBe($instante)
        ->and(AuditedPurgeReview::fromEntry(5, $instante, [])->auditEntryId)->toBe(5);
})->group('RS-14', 'RS-07', 'RL-04');

it('trata como salida el cierre de un asiento anterior a la v2.1.0, que no apuntaba la accion', function (): void {
    // Los cierres de la v2.0.0 y anteriores no llevan `action`: no existia la
    // pausa, asi que fueron salidas (RN-05, ADR-058). Un `break_start` puesto a
    // mano sobre ellos tiene que salir.
    $salida = conciliacionSalidaAuditada();
    $payload = $salida->payload;
    unset($payload['action']);
    $antigua = new AuditTrailEntry($salida->id, $salida->action, $payload);
    $asiento = AuditedShiftEntry::fromTrail($antigua, false, conciliacionEntradaAuditada(), $antigua);

    expect(conciliacionCompara(conciliacionFila(['closedBy' => 'clock_out']), $asiento))->toBeNull()
        ->and(conciliacionCompara(conciliacionFila(['closedBy' => 'break_start']), $asiento)?->fields)->toBe(['closed_by']);
})->group('RS-14', 'RL-04', 'RS-07', 'RN-05');

it('una marca relativa en el asiento es ilegible y no la hora del proceso (V1-AR-2)', function (string $relativa): void {
    // Con el analizador libre, `now` daba la hora de la pasada: la salida no
    // cuadraba y, ademas, los minutos se median contra el reloj del sistema, de
    // modo que el resultado dependia de cuando corriera la conciliacion.
    $hallazgo = conciliacionCompara(conciliacionFila(), conciliacionAsientoDeSalida(['clocked_out_at' => $relativa]));

    expect($hallazgo?->kind)->toBe(WorkRecordDiscrepancyKind::EntryDiffersFromAudit)
        ->and($hallazgo?->fields)->toContain('clocked_out_at')
        ->and($hallazgo?->fields)->not->toContain('duration_minutes');
})->with(['now', 'tomorrow', '+8 hours'])->group('RS-14', 'RL-04', 'RS-07');
