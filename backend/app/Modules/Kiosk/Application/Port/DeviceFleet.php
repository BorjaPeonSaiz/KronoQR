<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Port;

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
     * Registra el latido de un quiosco: `last_seen_at`, `app_version` y
     * `pending_queue_size` (doc 01 §5.5).
     *
     * **Los tres campos son informacion operativa, no autoridad.** Ninguno
     * influye en el registro horario: un dispositivo que mienta sobre su cola no
     * cambia ni un fichaje. Por eso se escriben tal y como los declara el
     * dispositivo, sin conciliarlos con nada.
     *
     * **El instante lo pone quien llama**, pidiendolo al puerto `Clock`: es un
     * dato del servidor y no del dispositivo, porque el sentido de `last_seen_at`
     * es «cuando supe de el por ultima vez», no «que hora cree que es».
     *
     * @param  int  $deviceId  Clave interna del dispositivo, resuelta del token.
     * @param  string  $appVersion  Version de la PWA que corre en la tablet.
     * @param  int  $pendingQueueSize  Fichajes sin sincronizar que declara el dispositivo.
     */
    public function recordHeartbeat(
        int $deviceId,
        string $appVersion,
        int $pendingQueueSize,
        DateTimeImmutable $seenAt,
    ): void;
}
