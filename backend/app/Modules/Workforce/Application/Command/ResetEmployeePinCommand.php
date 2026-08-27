<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Restablecer el PIN de una persona (RF-ID-09).
 *
 * **No lleva ningun PIN**: el que se emite lo decide el servidor. Aceptar uno
 * del cliente convertiria la robustez del PIN en lo que teclee quien rellena el
 * formulario, y el primero seria la fecha de nacimiento de alguien.
 *
 * **Tampoco lleva actor**: quien pidio el restablecimiento lo resuelve el
 * asiento de auditoria a partir de la sesion en curso.
 */
final readonly class ResetEmployeePinCommand
{
    public function __construct(public string $employeeUuid) {}
}
