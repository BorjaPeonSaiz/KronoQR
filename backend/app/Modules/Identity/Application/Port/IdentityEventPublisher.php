<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Shared\Domain\Event\DomainEvent;

/**
 * Publica los hechos de este modulo hacia el resto del sistema.
 *
 * **Es el enganche de la auditoria** (regla dura 6). `Identity` no puede
 * importar nada de `Compliance` —el §1.6 no concede esa arista— asi que la
 * emision y la revocacion de una credencial no llaman al escritor de
 * `audit_log`: publican su evento, y un listener de `Compliance/Infrastructure`
 * lo sella. Es la primera de las tres vias de comunicacion entre modulos del
 * §1.6, y la que el propio puerto `AuditTrail` nombra.
 *
 * **Se publica DENTRO de la transaccion del caso de uso**, y aqui esto es lo
 * contrario de lo que hace `WorkforceEventPublisher`. La diferencia no es un
 * descuido: el listener de auditoria escribe en `audit_log`, y ADR-027 exige que
 * si esa escritura falla **la accion auditada no se confirme**. Publicando
 * despues del commit, una credencial podria quedar emitida sin asiento, que es
 * exactamente el estado que la regla dura 6 no admite. Las colas no entran en
 * esto: los listeners de auditoria son sincronos.
 */
interface IdentityEventPublisher
{
    public function publish(DomainEvent ...$events): void;
}
