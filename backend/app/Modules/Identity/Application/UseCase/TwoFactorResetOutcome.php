<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

/**
 * Como termino una retirada de segundo factor ({@see ResetTwoFactorHandler}).
 *
 * Solo `Reset` escribe algo. Retirar un segundo factor que no estaba confirmado
 * no retira nada, y un asiento de «restablecimiento» sin nada restablecido
 * ensuciaria la pregunta que ese asiento existe para responder.
 */
enum TwoFactorResetOutcome
{
    /** Secreto retirado, sesiones cerradas y asiento escrito. */
    case Reset;

    /** No existe ninguna cuenta con ese `uuid`, o esta dada de baja (`404`). */
    case NotFound;

    /** Es la cuenta de quien lo pide (`409`). */
    case OwnAccount;

    /** La cuenta no tiene segundo factor confirmado: no hay nada que retirar (`409`). */
    case NotEnrolled;
}
