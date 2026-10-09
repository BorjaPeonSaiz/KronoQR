<?php

declare(strict_types=1);

use App\Modules\Kiosk\Domain\Policy\AppVersionPolicy;
use App\Modules\Kiosk\Domain\ValueObject\DeviceSummary;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthReason;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthRow;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthThresholds;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthVerdict;
use App\Modules\Kiosk\Domain\ValueObject\QueueStorage;

/*
 * El veredicto de un quiosco, juzgado directamente sobre la clase que lo decide
 * (**RF-PA-07**, ficha de la tarea 3.3, decisiones 2 y 5).
 *
 * ## Por que aqui y no solo en `CheckKioskHealthTest`
 *
 * Porque `KioskHealthRow::of()` la usan ahora **tres** consumidores —la consola
 * `kiosk:health`, `GET /api/v1/devices` y, a traves de el, el resaltado del
 * panel— y la ficha exige que los tres digan lo mismo (decision 2). Probar la
 * regla a traves del caso de uso de la consola deja las fronteras a merced de
 * los datos que aquel monta; probarla aqui las fija al segundo y a la unidad.
 *
 * Lo que se anade sobre lo que ya habia son las tres fronteras que la mutacion
 * demostro que nadie recorria: el limite del margen de gracia del recien
 * vinculado, **un solo** fichaje en la cola, y la cadena de prioridad completa
 * con todas las causas dandose a la vez.
 */

/** Los umbrales de serie, los mismos que `config/kiosk.php`. */
function umbralesDeFabrica(): KioskHealthThresholds
{
    return new KioskHealthThresholds(
        freshWithinSeconds: 120,
        silentAfterSeconds: 600,
        batteryLowPercent: 15,
    );
}

/**
 * La version del servidor: la MISMA que declaran los quioscos del fichero, para
 * que las pruebas que no hablan de version no se vean afectadas por ella.
 */
function versionDelServidor(string $deployed = '2.2.0'): AppVersionPolicy
{
    return AppVersionPolicy::forDeployed($deployed);
}

/** El instante contra el que se juzga en todo el fichero. */
function instanteDelJuicio(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-09-16 12:00:00', new DateTimeZone('UTC'));
}

function quioscoVinculadoEl(string $pairedAt): DeviceSummary
{
    return new DeviceSummary(
        id: 1,
        uuid: '0199a1f0-9c3d-7a21-9c1e-5f2b7d4e8a01',
        name: 'Recepcion',
        status: 'active',
        appVersion: null,
        lastSeenAt: null,
        pendingQueueSize: 0,
        pairedAt: new DateTimeImmutable($pairedAt, new DateTimeZone('UTC')),
    );
}

function quioscoQueLatioEl(
    string $lastSeenAt,
    int $pendingQueueSize = 0,
    ?int $batteryLevel = null,
    ?bool $batteryCharging = null,
): DeviceSummary {
    return new DeviceSummary(
        id: 1,
        uuid: '0199a1f0-9c3d-7a21-9c1e-5f2b7d4e8a01',
        name: 'Recepcion',
        status: 'active',
        appVersion: '2.2.0',
        lastSeenAt: new DateTimeImmutable($lastSeenAt, new DateTimeZone('UTC')),
        pendingQueueSize: $pendingQueueSize,
        pairedAt: new DateTimeImmutable('2026-09-16 08:00:00', new DateTimeZone('UTC')),
        batteryLevel: $batteryLevel,
        batteryCharging: $batteryCharging,
    );
}

// --- El margen del recien vinculado, al segundo -----------------------------

it('da margen al recien vinculado justo hasta el plazo de silencio, ni un segundo mas', function (
    string $pairedAt,
    KioskHealthVerdict $verdict,
    KioskHealthReason $reason,
): void {
    // El margen existe porque el runbook `alta-nuevo-quiosco.md` §4.2 manda
    // ejecutar `kiosk:health` justo despues de emparejar, y la tablet aun no ha
    // recogido su token. Pero es un margen, no una amnistia: pasado el plazo de
    // silencio, una tablet que nunca ha latido es un fallo que hay que ir a
    // mirar. La frontera es la MISMA cifra que la del silencio a proposito, para
    // no inventar un tercer numero.
    $row = KioskHealthRow::of(quioscoVinculadoEl($pairedAt), instanteDelJuicio(), umbralesDeFabrica(), versionDelServidor());

    expect($row->verdict)->toBe($verdict)
        ->and($row->reason)->toBe($reason)
        ->and($row->secondsSinceLastSeen)->toBeNull();
})->with([
    '599 s desde el alta: dentro del margen' => [
        '2026-09-16 11:50:01', KioskHealthVerdict::Warning, KioskHealthReason::AwaitingFirstHeartbeat,
    ],
    '600 s exactos: todavia dentro del margen' => [
        '2026-09-16 11:50:00', KioskHealthVerdict::Warning, KioskHealthReason::AwaitingFirstHeartbeat,
    ],
    '601 s: se acabo el margen' => [
        '2026-09-16 11:49:59', KioskHealthVerdict::Failure, KioskHealthReason::NeverSeen,
    ],
])->group('RF-PA-07');

// --- La cola, desde el primer fichaje ---------------------------------------

it('avisa por un solo fichaje pendiente en la cola', function (): void {
    // Uno basta. Ese fichaje es el registro horario de una persona real y se
    // pierde si alguien revoca el token antes de que drene (runbook §5.1), asi
    // que la columna no puede esperar a que se acumulen unos cuantos.
    $row = KioskHealthRow::of(
        quioscoQueLatioEl('2026-09-16 11:59:30', pendingQueueSize: 1),
        instanteDelJuicio(),
        umbralesDeFabrica(),
        versionDelServidor(),
    );

    expect($row->verdict)->toBe(KioskHealthVerdict::Warning)
        ->and($row->reason)->toBe(KioskHealthReason::QueuePending)
        ->and($row->pendingQueueSize)->toBe(1);
})->group('RF-PA-07');

it('no avisa por la cola de un quiosco al dia que no tiene nada pendiente', function (): void {
    $row = KioskHealthRow::of(
        quioscoQueLatioEl('2026-09-16 11:59:30'),
        instanteDelJuicio(),
        umbralesDeFabrica(),
        versionDelServidor(),
    );

    expect($row->verdict)->toBe(KioskHealthVerdict::Ok)
        ->and($row->reason)->toBe(KioskHealthReason::Beating);
})->group('RF-PA-07');

// --- La cadena de prioridad, con todas las causas dandose a la vez ----------

it('nombra una sola causa, la de mayor prioridad, cuando se dan todas a la vez', function (
    string $lastSeenAt,
    KioskHealthReason $reason,
): void {
    // La prioridad de la decision 5: callado -> tardio -> bateria -> cola. El
    // quiosco de este caso arrastra SIEMPRE cola y bateria baja descargandose; lo
    // unico que cambia entre filas es cuando latio. Una celda con dos causas no
    // la lee quien tiene una tablet apagada delante, y la que se nombre tiene que
    // ser la que hay que atender primero.
    $row = KioskHealthRow::of(
        quioscoQueLatioEl($lastSeenAt, pendingQueueSize: 9, batteryLevel: 4, batteryCharging: false),
        instanteDelJuicio(),
        umbralesDeFabrica(),
        versionDelServidor(),
    );

    expect($row->reason)->toBe($reason)
        ->and($row->verdict)->not->toBe(KioskHealthVerdict::Ok);
})->with([
    'callado desde hace tres horas' => ['2026-09-16 09:00:00', KioskHealthReason::Silent],
    'atrasado, 121 s' => ['2026-09-16 11:57:59', KioskHealthReason::Late],
    'al dia: manda la bateria sobre la cola' => ['2026-09-16 11:59:30', KioskHealthReason::BatteryLow],
])->group('RF-PA-07');

// --- ADR-047: la cola fuera de IndexedDB y los descartes sin avisar ---------

function quioscoConColaEn(
    string $lastSeenAt,
    QueueStorage $storage,
    ?int $pendingQueueSize,
    int $unreportedDiscards = 0,
    ?int $batteryLevel = null,
    ?bool $batteryCharging = null,
): DeviceSummary {
    return new DeviceSummary(
        id: 1,
        uuid: '0199a1f0-9c3d-7a21-9c1e-5f2b7d4e8a01',
        name: 'Recepcion',
        status: 'active',
        appVersion: '2.2.0',
        lastSeenAt: new DateTimeImmutable($lastSeenAt, new DateTimeZone('UTC')),
        pendingQueueSize: $pendingQueueSize,
        pairedAt: new DateTimeImmutable('2026-09-16 08:00:00', new DateTimeZone('UTC')),
        batteryLevel: $batteryLevel,
        batteryCharging: $batteryCharging,
        queueStorage: $storage,
        unreportedDiscards: $unreportedDiscards,
    );
}

it('marca como fallo la cola que cayo a memoria, con su tamano desconocido y no cero', function (QueueStorage $storage): void {
    $row = KioskHealthRow::of(
        quioscoConColaEn('2026-09-16 11:59:30', $storage, null),
        instanteDelJuicio(),
        umbralesDeFabrica(),
        versionDelServidor(),
    );

    expect($row->verdict)->toBe(KioskHealthVerdict::Failure)
        ->and($row->reason)->toBe(KioskHealthReason::QueueStorageDegraded)
        ->and($row->pendingQueueSize)->toBeNull();
})->with([
    'en memoria' => [QueueStorage::Memory],
    'sin donde guardar' => [QueueStorage::Unavailable],
])->group('RF-PA-07', 'RF-KI-04');

it('avisa por los descartes sin avisar de un quiosco al dia', function (): void {
    $row = KioskHealthRow::of(
        quioscoConColaEn('2026-09-16 11:59:30', QueueStorage::Durable, 0, unreportedDiscards: 1),
        instanteDelJuicio(),
        umbralesDeFabrica(),
        versionDelServidor(),
    );

    expect($row->verdict)->toBe(KioskHealthVerdict::Warning)
        ->and($row->reason)->toBe(KioskHealthReason::DiscardsUnreported);
})->group('RF-PA-07', 'RN-22');

it('respeta el orden del contrato con las causas nuevas dandose a la vez', function (
    string $lastSeenAt,
    QueueStorage $storage,
    KioskHealthReason $reason,
): void {
    // `DeviceHealth.reason`: revoked, never_seen, awaiting_first_heartbeat,
    // silent, queue_storage_degraded, late, discards_unreported, battery_low,
    // queue_pending, beating. El quiosco arrastra SIEMPRE descartes, bateria baja
    // descargandose y cola; cambian el latido y el almacenamiento.
    $row = KioskHealthRow::of(
        quioscoConColaEn(
            $lastSeenAt,
            $storage,
            $storage === QueueStorage::Durable ? 9 : null,
            unreportedDiscards: 3,
            batteryLevel: 4,
            batteryCharging: false,
        ),
        instanteDelJuicio(),
        umbralesDeFabrica(),
        versionDelServidor(),
    );

    expect($row->reason)->toBe($reason);
})->with([
    'callado manda sobre la cola en memoria' => ['2026-09-16 09:00:00', QueueStorage::Memory, KioskHealthReason::Silent],
    'la cola en memoria manda sobre el retraso' => ['2026-09-16 11:57:59', QueueStorage::Memory, KioskHealthReason::QueueStorageDegraded],
    'el retraso manda sobre los descartes' => ['2026-09-16 11:57:59', QueueStorage::Durable, KioskHealthReason::Late],
    'los descartes mandan sobre la bateria' => ['2026-09-16 11:59:30', QueueStorage::Durable, KioskHealthReason::DiscardsUnreported],
])->group('RF-PA-07', 'RF-KI-04', 'RN-22');

// --- La aplicacion desfasada, el ultimo de los avisos (2.2.1, bloque 1) -------

it('pone la aplicacion desfasada detras de todos los demas avisos', function (
    int $pending,
    ?int $batteryLevel,
    KioskHealthVerdict $verdict,
    KioskHealthReason $reason,
): void {
    // El quiosco declara 2.2.0 y el servidor esta en 2.2.1. La tablet se pone
    // al dia sola en cuanto su cola esta vacia: lo que se lo impide va antes.
    $row = KioskHealthRow::of(
        quioscoQueLatioEl('2026-09-16 11:59:30', $pending, $batteryLevel, $batteryLevel === null ? null : false),
        instanteDelJuicio(),
        umbralesDeFabrica(),
        versionDelServidor('2.2.1'),
    );

    expect($row->verdict)->toBe($verdict)
        ->and($row->reason)->toBe($reason);
})->with([
    'la bateria manda sobre la version' => [0, 5, KioskHealthVerdict::Warning, KioskHealthReason::BatteryLow],
    'la cola manda sobre la version' => [1, null, KioskHealthVerdict::Warning, KioskHealthReason::QueuePending],
    'sin nada mas, la version' => [0, null, KioskHealthVerdict::Warning, KioskHealthReason::AppVersionBehind],
])->group('RF-PA-07', 'RF-KI-07');

it('no mira la version de un quiosco revocado ni de uno que aun no ha latido', function (): void {
    $revocado = new DeviceSummary(
        id: 1,
        uuid: '0199a1f0-9c3d-7a21-9c1e-5f2b7d4e8a01',
        name: 'Recepcion',
        status: 'revoked',
        appVersion: '0.0.0',
        lastSeenAt: new DateTimeImmutable('2026-09-16 11:59:30', new DateTimeZone('UTC')),
        pendingQueueSize: 0,
        pairedAt: new DateTimeImmutable('2026-09-16 08:00:00', new DateTimeZone('UTC')),
    );

    $sinLatido = KioskHealthRow::of(quioscoVinculadoEl('2026-09-16 11:59:00'), instanteDelJuicio(), umbralesDeFabrica(), versionDelServidor('2.2.1'));

    expect(KioskHealthRow::of($revocado, instanteDelJuicio(), umbralesDeFabrica(), versionDelServidor('2.2.1'))->reason)
        ->toBe(KioskHealthReason::Revoked)
        ->and($sinLatido->reason)->toBe(KioskHealthReason::AwaitingFirstHeartbeat);
})->group('RF-PA-07', 'RF-KI-07');
