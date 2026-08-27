<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Command;

use DateTimeImmutable;

/**
 * La orden de registrar el latido de un quiosco (RF-PA-07, doc 01 §5.5).
 *
 * DTO `readonly` con los datos ya tipados y validados en el borde.
 *
 * **El dispositivo no viaja en el cuerpo de la peticion**, igual que en el
 * fichaje: sus dos identificadores salen del token autenticado. Si viajaran en el
 * cuerpo, cualquier portador podria declarar la cola de otro quiosco y el panel de
 * salud diria que la tablet averiada es otra.
 *
 * **`oldestPendingAt` es opcional y su ausencia significa algo**: no hay cola. Es
 * lo que distingue «37 pendientes de hace un minuto» —una sincronizacion en
 * curso— de «37 pendientes de hace tres horas», que es una tablet incomunicada.
 */
final readonly class RecordHeartbeatCommand
{
    public function __construct(
        public int $deviceId,
        public string $deviceUuid,
        public string $appVersion,
        public int $pendingQueueSize,
        public ?DateTimeImmutable $oldestPendingAt = null,
    ) {}
}
