<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * Se intenta dar de baja —o modificar— a quien ya esta de baja.
 *
 * **La baja no se repite ni se deshace por aqui.** Repetirla reescribiria la
 * fecha de cese, que es el dato desde el que cuenta la retencion de RL-02 y el
 * que decide desde cuando esa persona no podia fichar (RN-14). Cambiar un dato
 * asi exige una correccion trazada (RN-13, tarea 1.15), no un segundo `POST`.
 */
final class EmployeeAlreadyTerminated extends WorkforceDomainException
{
    public static function withUuid(string $uuid): self
    {
        return new self('El empleado '.$uuid.' ya estaba dado de baja.');
    }
}
