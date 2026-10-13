<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

/**
 * Quien puede responder de una revision (decision D7 del bloque 5 de la
 * 2.2.1): cualquier cuenta de gestion activa, como `--responsible` de
 * `compliance:apply-retention`. No se exige un rol concreto.
 */
interface ResponsibleAccounts
{
    public function isActiveManagementAccount(int $userId): bool;
}
