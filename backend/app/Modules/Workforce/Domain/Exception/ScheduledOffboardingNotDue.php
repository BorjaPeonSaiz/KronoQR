<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

use App\Modules\Workforce\Domain\Model\Employee;

/**
 * Se intenta aplicar una baja programada que no existe o cuyo cese aun no ha
 * pasado (RN-14, ADR-062 §4).
 *
 * **No es un rechazo de negocio** y por eso no implementa `BusinessRejection`:
 * la tarea del sistema solo llama a {@see Employee::applyScheduledOffboarding()}
 * despues de comprobar que la baja vencio, con la ficha bloqueada. Si llega
 * aqui, el defecto es del llamador, y aplicar la baja igualmente cortaria el
 * fichaje de alguien que todavia trabaja.
 */
final class ScheduledOffboardingNotDue extends WorkforceDomainException
{
    public static function withUuid(string $uuid): self
    {
        return new self(sprintf('The employee %s has no due scheduled offboarding (RN-14).', $uuid));
    }
}
