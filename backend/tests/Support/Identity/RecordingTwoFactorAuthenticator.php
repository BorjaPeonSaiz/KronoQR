<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use App\Modules\Identity\Application\Port\TwoFactorAuthenticator;
use SensitiveParameter;

/**
 * Espia del algoritmo TOTP: **delega en el real y cuenta las verificaciones**.
 *
 * Cuenta `verify()` porque ahi vive el trabajo caro —los HMAC de cada franja de la
 * ventana de tolerancia—, que es lo que un rechazo tiene que pagar SIEMPRE, tenga
 * la cuenta secreto activo o no (RS-03, regla dura 17). Saltarselo cuando no hay
 * secreto convertia la ausencia de segundo factor en algo medible desde fuera.
 *
 * **Nunca apunta el secreto ni el codigo** (regla dura 21): la prueba compara
 * cuantas veces se llamo, no con que.
 */
final class RecordingTwoFactorAuthenticator implements TwoFactorAuthenticator
{
    public int $verifications = 0;

    public function __construct(private readonly TwoFactorAuthenticator $inner) {}

    public function generateSecret(): string
    {
        return $this->inner->generateSecret();
    }

    public function otpauthUriFor(string $account, #[SensitiveParameter] string $secret): string
    {
        return $this->inner->otpauthUriFor($account, $secret);
    }

    public function verify(
        #[SensitiveParameter] string $secret,
        #[SensitiveParameter] string $code,
        ?int $notBeforeSlice,
    ): ?int {
        $this->verifications++;

        return $this->inner->verify($secret, $code, $notBeforeSlice);
    }
}
