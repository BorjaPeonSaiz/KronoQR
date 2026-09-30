<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Policy;

use App\Modules\Identity\Domain\Exception\CredentialHolderIsOffboarded;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;

/**
 * **A quien se le puede emitir una tarjeta** (RN-14, RF-QR-01).
 *
 * Pura: recibe el estado laboral ya resuelto por el puerto
 * `Shared\Application\Port\EmploymentStatusLookup` y no consulta nada. La
 * unica situacion que prohibe es la baja; ver
 * {@see CredentialHolderIsOffboarded} para el porque y para lo que deliberadamente
 * no prohibe.
 */
final readonly class CredentialIssuancePolicy
{
    /**
     * @throws CredentialHolderIsOffboarded
     */
    public function assertMayReceiveCredential(EmploymentStatus $status): void
    {
        if ($status === EmploymentStatus::TERMINATED) {
            throw CredentialHolderIsOffboarded::make();
        }
    }
}
