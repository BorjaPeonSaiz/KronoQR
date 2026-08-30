<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * La revision diaria ha terminado de recorrer su ventana (RF-PR-01).
 *
 * **Por que existe.** RF-PR-01 pide avisar al responsable, y el aviso util es
 * **un resumen por persona y por ejecucion**, no un correo por incidencia: una
 * madrugada con quince hallazgos en el mismo departamento son quince correos que
 * nadie lee. Para agrupar hace falta saber cuando ha terminado la pasada, y eso
 * es justo lo que este evento dice.
 *
 * `Attendance` no envia nada: emite, y `Compliance` —que es quien tiene las
 * incidencias, sus responsables y su columna `notified_at`— decide a quien
 * escribe (doc 02 §1.6).
 *
 * Las cifras que lleva son **recuentos**, nunca personas: sirven para el log de
 * la ejecucion y para saber si la pasada encontro algo.
 */
final readonly class AttendanceReviewCompleted implements DomainEvent
{
    public function __construct(
        public int $siteId,
        /** Instante en que termino la pasada, en UTC. */
        public DateTimeImmutable $completedAt,
        /** Dias de la ventana revisada (RF-PR-01, decision de retroactividad). */
        public int $daysInspected,
        /** Hallazgos emitidos en esta pasada, antes de deduplicar contra lo ya abierto. */
        public int $anomaliesDetected,
    ) {}

    #[\Override]
    public function eventName(): string
    {
        return 'attendance.review_completed';
    }

    #[\Override]
    public function occurredAt(): DateTimeImmutable
    {
        return $this->completedAt;
    }
}
