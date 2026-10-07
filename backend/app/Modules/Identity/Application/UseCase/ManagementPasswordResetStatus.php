<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

/**
 * Los desenlaces de un restablecimiento de contrasena (RF-ID-10).
 */
enum ManagementPasswordResetStatus
{
    /** Contrasena temporal emitida, sesiones cerradas y asiento escrito. */
    case Reset;

    /** No existe ninguna cuenta con ese `uuid`, o esta dada de baja (`404`). */
    case NotFound;

    /** Es la propia cuenta: para eso esta el cambio propio, que pide la actual (`409`). */
    case OwnAccount;
}
