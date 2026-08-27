<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha emitido un PIN para una persona: en su alta o al restablecerlo
 * (RF-ID-09).
 *
 * **Un evento y no dos, con `reset` para distinguirlos**, igual que
 * `CredentialIssued` distingue la reemision. Los dos hechos son el mismo —hay un
 * PIN nuevo y el anterior, si lo habia, ya no vale—, pero el asiento de
 * auditoria tiene que poder decir cual fue: `pin.issued` y `pin.reset` responden
 * preguntas distintas cuando alguien revisa por que una persona tuvo tres PIN en
 * un ano.
 *
 * **Sin el PIN.** Este evento acaba en `audit_log`, y ahi consta que hubo
 * emision, no que se emitio (regla dura 21, RF-ID-09). Tampoco viaja el hash: no
 * hay nada que hacer con el fuera del repositorio que lo escribio.
 *
 * `siteId` viaja porque es la etiqueta de `pin_resets_total{site}` y porque el
 * alcance por centro de la Fase 2 lo necesitara; no identifica a nadie.
 *
 * **Sin actor.** Quien lo pidio lo resuelve el asiento de auditoria a partir de
 * la sesion en curso: es una propiedad de la peticion, no del hecho, y un evento
 * de dominio que la transportara obligaria al dominio a conocer el transporte.
 */
final readonly class EmployeePinIssued implements DomainEvent
{
    public function __construct(
        public string $employeeUuid,
        public int $siteId,
        /** `true` si sustituye a un PIN anterior: es la diferencia entre `pin.reset` y `pin.issued`. */
        public bool $reset,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return $this->reset ? 'workforce.employee_pin_reset' : 'workforce.employee_pin_issued';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
