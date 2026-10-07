<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

/**
 * Como termino un cambio de la contrasena propia ({@see ChangeOwnPasswordHandler}).
 *
 * El bloqueo por intentos no esta aqui: sale como `AccountTemporarilyLocked`,
 * que ya se traduce a `429` con `Retry-After` en todo el producto.
 */
enum ChangeOwnPasswordOutcome
{
    /** Contrasena cambiada, demas sesiones cerradas y la actual con los ambitos del rol. */
    case Changed;

    /** La contrasena actual no coincide (`422` en `current_password`). Cuenta para el bloqueo. */
    case WrongCurrentPassword;

    /** La nueva es igual a la actual (`422` en `new_password`). */
    case SameAsCurrent;

    /** Alguien sustituyo la contrasena entre la comprobacion y la escritura (`409`). */
    case ChangedMeanwhile;

    /**
     * La sesion ya no sirve: la cuenta no existe o esta de baja, su temporal
     * caduco o el token se revoco mientras tanto (`401`).
     */
    case SessionGone;
}
