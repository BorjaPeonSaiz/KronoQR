<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Policy;

/**
 * Lo que decide {@see ManagementAccountDeactivationGuard} sobre una baja
 * (**RF-ID-10**).
 */
enum DeactivationVerdict
{
    /** Se puede dar de baja. */
    case Allowed;

    /** Es la cuenta de quien la da de baja. */
    case OwnAccount;

    /** Es la ultima cuenta `admin` activa de la instalacion. */
    case LastActiveAdmin;
}
