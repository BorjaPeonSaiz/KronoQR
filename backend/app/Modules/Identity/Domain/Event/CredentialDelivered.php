<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * La tarjeta ha llegado a manos de su titular, con momento y responsable
 * (RF-QR-06).
 *
 * **No es burocracia, y el doc 02 §5.5 dice por que**: *«es lo que distingue "la
 * tarjeta se perdio antes de darsela" de "el empleado la perdio", que son
 * incidencias distintas»*. Sin este asiento, las dos se ven igual desde la base
 * de datos —una credencial impresa que hay que revocar— y la segunda tiene
 * consecuencias para una persona que la primera no tiene.
 *
 * Es tambien lo que cierra el caso del riesgo residual de ADR-034: si la
 * respuesta del PDF se perdio y la tarjeta nunca se imprimio de verdad, la
 * credencial esta impresa y **sin entregar**, que es exactamente lo que
 * `delivered_at` distingue.
 *
 * **`deliveredByUserId` y `actorUserId` no son lo mismo, aunque casi siempre
 * coincidan.** El primero es dato de negocio —quien firma que entrego la
 * tarjeta, RF-QR-06— y el segundo es quien manejo el sistema. Por la API son la
 * misma persona; por consola no hay sesion, asi que el actor es el sistema y el
 * responsable llega declarado en la orden. Confundirlos dejaria asientos sin
 * responsable o responsables inventados.
 *
 * **Lo que NO lleva**: el nombre de nadie (regla dura 21). Titular por
 * `employeeUuid`, responsable por su clave de usuario.
 */
final readonly class CredentialDelivered implements DomainEvent
{
    public function __construct(
        public int $credentialId,
        public string $credentialUuid,
        public string $employeeUuid,
        /** Quien responde de la entrega (`credentials.delivered_by_user_id`, RF-QR-06). */
        public int $deliveredByUserId,
        /** Quien manejo el sistema, o `null` si fue un comando de consola sin sesion. */
        public ?int $actorUserId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'identity.credential_delivered';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
