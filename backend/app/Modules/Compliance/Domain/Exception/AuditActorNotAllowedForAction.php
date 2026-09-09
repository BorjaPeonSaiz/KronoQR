<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Exception;

/**
 * Se ha intentado firmar con un actor humano una accion que solo puede escribir
 * el sistema (RF-PD-10, RL-04, tarea 5.7).
 *
 * **Por que es un error del dominio y no una comprobacion de la interfaz.** Un
 * `system.restored_from_backup` con `actor_type = user` afirma que una persona
 * concreta restauro la base de datos de este hotel. No es un matiz: es la
 * diferencia entre «el instalador volvio atras solo» y «alguien decidio
 * descartar un intervalo del registro». La tabla no admite `UPDATE`, asi que esa
 * afirmacion no se puede corregir despues.
 *
 * Y al reves tambien importa: si el asiento se pudiera firmar con cualquier
 * actor, seria la via mas comoda para colocar en el trail una explicacion de una
 * discontinuidad que en realidad provoco otra cosa.
 */
final class AuditActorNotAllowedForAction extends ComplianceDomainException
{
    public function __construct(string $action, string $actorType)
    {
        parent::__construct(sprintf(
            'La accion de auditoria «%s» solo puede escribirla el actor «system»; se recibio «%s». '
            .'La escribe el instalador, sin sesion de nadie detras.',
            $action,
            $actorType,
        ));
    }
}
