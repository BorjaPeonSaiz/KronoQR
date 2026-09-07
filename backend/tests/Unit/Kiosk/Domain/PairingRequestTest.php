<?php

declare(strict_types=1);

use App\Modules\Kiosk\Domain\Model\PairingRequest;
use App\Modules\Kiosk\Domain\ValueObject\ClaimOutcome;
use App\Modules\Kiosk\Domain\ValueObject\ConfirmOutcome;
use App\Modules\Kiosk\Domain\ValueObject\PairingStatus;

/*
 * Las invariantes de la solicitud de emparejamiento (RF-PD-06, tarea 5.6).
 *
 * Unitarias y sin base de datos: el agregado recibe el instante ya resuelto
 * (regla dura 2) y decide con la foto que tiene delante. Quien arbitra la
 * concurrencia es PostgreSQL, y eso se prueba en integracion.
 *
 * **Lo que estas pruebas defienden**, en orden de lo que mas caro sale si se
 * rompe:
 *
 *   1. Que el `claim` comprueba el secreto **primero y en todas las ramas**. Si
 *      alguien invirtiera el orden por legibilidad, «pendiente» y «rechazada»
 *      dejarian de costar lo mismo y la ruta publica se convertiria en un
 *      comprobador de `pairing_id` (regla dura 17, RS-03).
 *   2. Que la caducidad **deja de aplicar una vez confirmada**. Es la decision
 *      menos obvia del agregado, y su ausencia deja quioscos dados de alta que
 *      ninguna tablet puede usar (regla dura 19).
 *   3. Que el borde de la caducidad es exacto. Un `>` en lugar de `>=` no lo
 *      nota nadie a mano.
 *   4. Que un rechazo no consume ni caduca nada: teclear mal no puede dejar sin
 *      emparejar una tablet que estaba bien.
 */

const AHORA = '2026-09-07T10:00:00Z';

function solicitud(
    PairingStatus $status = PairingStatus::Pending,
    string $expiresAt = '2026-09-07T10:10:00Z',
    ?int $deviceId = null,
): PairingRequest {
    return PairingRequest::reconstitute(
        uuid: '0199f3c1-4a2b-7e55-9c10-8d7e6f5a4b32',
        status: $status,
        expiresAt: new DateTimeImmutable($expiresAt, new DateTimeZone('UTC')),
        deviceId: $deviceId,
    );
}

function instante(string $wallClock = AHORA): DateTimeImmutable
{
    return new DateTimeImmutable($wallClock, new DateTimeZone('UTC'));
}

// --- Caducidad ---------------------------------------------------------------

it('caduca en el instante EXACTO de expires_at y no un microsegundo despues', function (): void {
    // El borde. `expires_at` es el primer instante en que el codigo ya no vale,
    // no el ultimo en que valia: con `>` en lugar de `>=`, una confirmacion
    // hecha justo en ese microsegundo pasaria. Es el unico sitio donde este
    // agregado se puede equivocar sin que nadie lo note a mano.
    $solicitud = solicitud(expiresAt: '2026-09-07T10:10:00Z');

    expect($solicitud->hasExpiredAt(instante('2026-09-07T10:09:59.999999Z')))->toBeFalse()
        ->and($solicitud->hasExpiredAt(instante('2026-09-07T10:10:00Z')))->toBeTrue()
        ->and($solicitud->hasExpiredAt(instante('2026-09-07T10:10:00.000001Z')))->toBeTrue();
})->group('RF-PD-06');

it('no admite confirmar un codigo caducado', function (): void {
    expect(solicitud(expiresAt: '2026-09-07T10:10:00Z')->confirm(instante('2026-09-07T10:10:00Z')))
        ->toBe(ConfirmOutcome::Rejected);
})->group('RF-PD-06');

it('admite confirmar dentro de plazo', function (): void {
    // El control positivo. Sin el, todo lo de arriba pasaria igual con un
    // `confirm()` que devolviera `Rejected` siempre.
    expect(solicitud()->confirm(instante()))->toBe(ConfirmOutcome::Confirmed);
})->group('RF-PD-06');

// --- Un solo uso -------------------------------------------------------------

it('no admite confirmar dos veces la misma solicitud', function (): void {
    // Dos personas tecleando el mismo codigo no pueden dar de alta dos quioscos.
    // La segunda recibe el rechazo generico y no se entera de cual de las causas
    // fue: al administrador le cambia lo mismo —pedir otro codigo— en las tres.
    expect(solicitud(PairingStatus::Confirmed, deviceId: 7)->confirm(instante()))
        ->toBe(ConfirmOutcome::Rejected)
        ->and(solicitud(PairingStatus::Claimed, deviceId: 7)->confirm(instante()))
        ->toBe(ConfirmOutcome::Rejected);
})->group('RF-PD-06');

it('no entrega el token dos veces', function (): void {
    // El «un solo uso» del contrato. Una vez consumida, el sondeo se rechaza
    // aunque el secreto sea correcto.
    expect(solicitud(PairingStatus::Claimed, deviceId: 7)->claim(instante(), secretMatches: true))
        ->toBe(ClaimOutcome::Rejected);
})->group('RF-PD-06');

// --- El secreto, primero y en todas las ramas --------------------------------

it('comprueba el secreto ANTES que el estado en las tres situaciones', function (
    PairingStatus $status,
    ?int $deviceId,
): void {
    // Regla dura 17. Con el estado mirado primero, una solicitud PENDIENTE con el
    // secreto equivocado devolveria `Pending` —diciendo que existe— y una
    // CONFIRMADA devolveria `Paired` a quien no la pidio. Que las tres den
    // `Rejected` es lo que hace que «no existe», «no es tuya» y «ya no vale»
    // sean el mismo camino.
    expect(solicitud($status, deviceId: $deviceId)->claim(instante(), secretMatches: false))
        ->toBe(ClaimOutcome::Rejected);
})->with([
    'pendiente' => [PairingStatus::Pending, null],
    'confirmada' => [PairingStatus::Confirmed, 7],
    'consumida' => [PairingStatus::Claimed, 7],
])->group('RF-PD-06', 'RS-03');

it('devuelve pending mientras nadie confirme y el codigo siga vivo', function (): void {
    expect(solicitud()->claim(instante(), secretMatches: true))->toBe(ClaimOutcome::Pending);
})->group('RF-PD-06');

it('rechaza el sondeo de un codigo pendiente que ya caduco', function (): void {
    // La tablet vuelve al paso 1 y pide otro codigo sin que nadie la toque
    // (regla dura 19).
    expect(solicitud(expiresAt: '2026-09-07T10:10:00Z')->claim(instante('2026-09-07T10:10:00Z'), secretMatches: true))
        ->toBe(ClaimOutcome::Rejected);
})->group('RF-PD-06');

// --- La decision menos obvia -------------------------------------------------

it('entrega el token de una solicitud CONFIRMADA aunque su plazo haya pasado', function (): void {
    // **La caducidad no aplica al claim una vez confirmada.** Despues del
    // `confirm` la fila de `devices` ya existe y esta activa: negar la recogida
    // dejaria un quiosco dado de alta que ninguna tablet puede usar, y eso solo
    // se arregla entrando por consola — justo lo que RF-PD-06 existe para evitar
    // (regla dura 19).
    //
    // Una hora despues de caducar, y sigue entregando.
    $solicitud = solicitud(PairingStatus::Confirmed, expiresAt: '2026-09-07T10:10:00Z', deviceId: 7);

    expect($solicitud->claim(instante('2026-09-07T11:10:00Z'), secretMatches: true))
        ->toBe(ClaimOutcome::Paired);
})->group('RF-PD-06');

it('no deja que la caducidad rescate a una solicitud confirmada con el secreto equivocado', function (): void {
    // El cruce de las dos reglas anteriores: que la caducidad no aplique no
    // significa que el secreto deje de comprobarse.
    $solicitud = solicitud(PairingStatus::Confirmed, expiresAt: '2026-09-07T10:10:00Z', deviceId: 7);

    expect($solicitud->claim(instante('2026-09-07T11:10:00Z'), secretMatches: false))
        ->toBe(ClaimOutcome::Rejected);
})->group('RF-PD-06', 'RS-03');

// --- Nada muta ---------------------------------------------------------------

it('no cambia de estado con un confirm ni con un claim fallidos', function (): void {
    // Un `confirm` fallido NO consume ni caduca la solicitud, y un secreto
    // equivocado tampoco. Si lo hicieran, teclear mal tres veces dejaria sin
    // emparejar una tablet que estaba perfectamente, y bastaria sondear con un
    // secreto inventado para tumbar el alta de un quiosco ajeno.
    $solicitud = solicitud();

    $caducado = $solicitud->confirm(instante('2026-09-07T10:20:00Z'));
    $secretoMalo = $solicitud->claim(instante(), secretMatches: false);

    expect($caducado)->toBe(ConfirmOutcome::Rejected)
        ->and($secretoMalo)->toBe(ClaimOutcome::Rejected)
        // Y despues de los dos, la solicitud sigue intacta.
        ->and($solicitud->status)->toBe(PairingStatus::Pending)
        // Y sigue sirviendo: el que la teclea bien despues, la confirma.
        ->and($solicitud->confirm(instante()))->toBe(ConfirmOutcome::Confirmed);
})->group('RF-PD-06');
