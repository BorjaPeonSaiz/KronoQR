<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien administra las cuentas de gestion (**RF-ID-10**, regla dura 18):
 * `admin` y solo `admin`, **y nunca un acceso de soporte**.
 *
 * ## Por que dos comprobaciones y no una
 *
 * El ambito `accounts:*` lo comprueba el middleware de la ruta; esto es la otra
 * mitad. Un token de soporte con alcance `configuration` actua como `admin`
 * ante las policies —tiene que hacerlo para el resto de su trabajo—, y crear una
 * cuenta `admin` es exactamente como un acceso temporal se vuelve permanente
 * (ADR-020, regla dura 16). Mismo cierre que `DataExportPolicy` y
 * `SupportGrantPolicy`.
 *
 * **Un metodo por accion aunque hoy digan lo mismo**: es el punto donde se
 * endurece una sin tocar las demas —por ejemplo, si `seguridad-cumplimiento`
 * decide que el segundo factor de otra `admin` no se retira desde el panel—.
 *
 * Se tipa `mixed` y no `ManagementActor` para que un `tokenable` de otra clase
 * —el quiosco, la sesion de portal— llegue aqui y reciba un `false` explicito.
 */
final class ManagementAccountPolicy
{
    public function viewAny(mixed $actor): bool
    {
        return $this->isInstallationAdministrator($actor);
    }

    public function create(mixed $actor): bool
    {
        return $this->isInstallationAdministrator($actor);
    }

    public function deactivate(mixed $actor): bool
    {
        return $this->isInstallationAdministrator($actor);
    }

    public function resetPassword(mixed $actor): bool
    {
        return $this->isInstallationAdministrator($actor);
    }

    public function resetTwoFactor(mixed $actor): bool
    {
        return $this->isInstallationAdministrator($actor);
    }

    private function isInstallationAdministrator(mixed $actor): bool
    {
        return $actor instanceof ManagementActor
            && ! $actor->isSupportActor()
            && $actor->actsAs(UserRole::ADMIN);
    }
}
