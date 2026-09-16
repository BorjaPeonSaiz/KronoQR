<?php

declare(strict_types=1);

use App\Modules\Kiosk\Domain\ValueObject\DeviceSummary;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthReason;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthRow;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthThresholds;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthVerdict;

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
    $row = KioskHealthRow::of(quioscoVinculadoEl($pairedAt), instanteDelJuicio(), umbralesDeFabrica());

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
    );

    expect($row->reason)->toBe($reason)
        ->and($row->verdict)->not->toBe(KioskHealthVerdict::Ok);
})->with([
    'callado desde hace tres horas' => ['2026-09-16 09:00:00', KioskHealthReason::Silent],
    'atrasado, 121 s' => ['2026-09-16 11:57:59', KioskHealthReason::Late],
    'al dia: manda la bateria sobre la cola' => ['2026-09-16 11:59:30', KioskHealthReason::BatteryLow],
])->group('RF-PA-07');
