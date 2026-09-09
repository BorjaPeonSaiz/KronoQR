<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\UseCase;

use App\Modules\Shared\Application\Port\ErrorEventSink;
use DateTimeImmutable;

/**
 * Lo que el servidor le devuelve al quiosco tras un latido: el esquema
 * `KioskHeartbeat` del contrato (RF-PA-07, RF-PD-15).
 *
 * ## Por que un objeto y no un instante suelto
 *
 * Hasta la tarea 5.12 {@see RecordHeartbeat} devolvia solo la hora del servidor.
 * Ahora el latido tambien acusa recibo de los errores que la tablet adjunto, y
 * los dos datos tienen que viajar juntos: `client_errors_accepted` es lo que la
 * PWA usa para vaciar de su buffer **solo lo confirmado** (`acknowledge(n)`), de
 * modo que lo que no se pudo guardar vuelve en el latido siguiente en vez de
 * perderse (decision 7 de la ficha).
 *
 * ## `clientErrorsAccepted` es un prefijo, no un total
 *
 * El puerto {@see ErrorEventSink} se detiene
 * en el primero que no pudo guardar, asi que el numero cuenta **desde el mas
 * antiguo y sin huecos**. Si fuera un recuento de aciertos sueltos, la tablet
 * descartaria errores que nadie persistio.
 */
final readonly class HeartbeatOutcome
{
    public function __construct(
        /** La hora del servidor: con ella la tablet mide su desfase de reloj y avisa (RF-AT-10). */
        public DateTimeImmutable $seenAt,
        /** Cuantos de los `client_errors` recibidos quedaron en el historico. `0` si no vino ninguno. */
        public int $clientErrorsAccepted,
    ) {}
}
