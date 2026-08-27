<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Domain\Model\Credential;
use App\Modules\Identity\Domain\ValueObject\CredentialLifecycleStatus;
use App\Modules\Shared\Domain\ValueObject\EmployeeCardProfile;

/**
 * Una fila del panel de estado de credenciales (RF-QR-08): **una persona y la
 * situacion de su tarjeta**.
 *
 * La fila es del empleado y no de la credencial, y esa es la decision de diseño
 * que hace util al panel: la pregunta que RRHH tiene delante es «¿quien no puede
 * fichar mañana?», y quien no puede fichar porque nunca se le emitio nada no
 * tiene ninguna credencial que listar. Un panel de credenciales dejaria fuera
 * precisamente a esas personas.
 *
 * `credential` es la vigente y, si no le queda ninguna activa, la ultima que
 * tuvo. Es `null` solo cuando nunca hubo ninguna.
 */
final readonly class CredentialStatusRow
{
    public function __construct(
        public EmployeeCardProfile $employee,
        public CredentialLifecycleStatus $status,
        public ?Credential $credential = null,
    ) {}
}
