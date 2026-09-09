<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Command;

use App\Modules\Shared\Domain\ValueObject\ErrorReport;
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
 *
 * **`clientErrors` viaja aqui y no por un canal propio** (tarea 5.12, decision 7).
 * El quiosco no abre otra conexion de red que compita con la sincronizacion de la
 * cola en un cambio de turno: los errores de la tablet suben pegados al latido que
 * ya sube cada minuto. Llegan **ya tipados** desde el `FormRequest`, con su origen
 * y su severidad puestos por el servidor —nunca por el cuerpo de la peticion—, y
 * sin sanear: el saneado es del servidor y ocurre al persistir (decision 5).
 */
final readonly class RecordHeartbeatCommand
{
    /**
     * @param  list<ErrorReport>  $clientErrors  Como maximo 50, en orden de antiguedad: el acuse es un prefijo.
     *
     * @see ErrorReport El tipo del que habla el `@param` de arriba; nombrarlo aqui
     *                  ademas evita que la herramienta de estilo retire su `use`
     *                  al no verlo en ninguna firma.
     */
    public function __construct(
        public int $deviceId,
        public string $deviceUuid,
        public string $appVersion,
        public int $pendingQueueSize,
        public ?DateTimeImmutable $oldestPendingAt = null,
        public array $clientErrors = [],
    ) {}
}
