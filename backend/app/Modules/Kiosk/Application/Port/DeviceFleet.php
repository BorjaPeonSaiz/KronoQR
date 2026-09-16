<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Port;

use App\Modules\Kiosk\Domain\ValueObject\HeartbeatTelemetry;
use DateTimeImmutable;

/**
 * Los quioscos, vistos por los casos de uso de este modulo (doc 01 §5.5,
 * RF-PA-07).
 *
 * `Device` es raiz de agregado en `Kiosk` —lo fija el doc 01 §5.5— y este puerto
 * es la puerta a su telemetria. **`Identity` emite y revoca su token**, que es
 * otra cosa y vive en otro modulo: aqui no se valida ningun token ni se consulta
 * ninguno. Un dispositivo no valida su propio token, recibe el resultado de esa
 * validacion.
 *
 * Habla en escalares y en `DateTimeImmutable`, nunca en modelos Eloquent
 * (ADR-025, restriccion 2).
 */
interface DeviceFleet
{
    /**
     * Registra el latido de un quiosco: `last_seen_at` y toda la telemetria que
     * el dispositivo declara de si mismo (doc 01 §5.5, tarea 3.3).
     *
     * **Todo lo que llega aqui es informacion operativa, no autoridad.** Ningun
     * campo influye en el registro horario: un dispositivo que mienta sobre su
     * cola no cambia ni un fichaje. Por eso se escriben tal y como los declara el
     * dispositivo, sin conciliarlos con nada.
     *
     * **La telemetria viaja en un objeto y no en escalares sueltos**: ya eran
     * tres y la tarea 3.3 anadio dos mas, y cinco parametros posicionales de
     * tipos primitivos son un sitio donde cruzar la cola con la bateria sin que
     * el tipado lo note. Ver {@see HeartbeatTelemetry}.
     *
     * **El instante lo pone quien llama**, pidiendolo al puerto `Clock`: es un
     * dato del servidor y no del dispositivo, porque el sentido de `last_seen_at`
     * es «cuando supe de el por ultima vez», no «que hora cree que es».
     *
     * @param  int  $deviceId  Clave interna del dispositivo, resuelta del token.
     */
    public function recordHeartbeat(
        int $deviceId,
        HeartbeatTelemetry $telemetry,
        DateTimeImmutable $seenAt,
    ): void;
}
