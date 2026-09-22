<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use App\Modules\Identity\Application\Port\AccessTokenIssuer;
use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Identity\Domain\ValueObject\IssuedAccessToken;
use LogicException;

/**
 * Apunta a quien se le cierran las sesiones, sin Sanctum ni base de datos
 * (RS-05, RS-06).
 *
 * **Lo que esta clase existe para poder afirmar** es la mitad que se olvida de
 * una baja: marcar `is_active` a `false` y dejar viva la sesion abierta en una
 * tablet no da de baja a nadie durante las doce horas siguientes. Con el puerto
 * por medio, eso se comprueba sin emitir un token de verdad.
 *
 * **Los otros tres metodos lanzan.** Ninguno de los dos casos de uso del ciclo
 * de vida tiene por que emitir un token ni revocar uno suelto: si algun dia lo
 * hicieran, la prueba unitaria se rompe y lo dice, que es mejor que un cuerpo
 * vacio que se lo traga.
 */
final class RecordingAccessTokens implements AccessTokenIssuer
{
    /**
     * Los uuid cuyas sesiones se han cerrado del todo, en orden de llamada.
     *
     * @var list<string>
     */
    public array $revokedAccounts = [];

    public function issueFor(AuthenticatedUser $user, string $deviceName): IssuedAccessToken
    {
        throw new LogicException('El ciclo de vida de una cuenta no emite sesiones.');
    }

    public function issuePendingFor(AuthenticatedUser $user, string $deviceName): IssuedAccessToken
    {
        throw new LogicException('El ciclo de vida de una cuenta no emite retos de segundo factor.');
    }

    public function revoke(int|string $tokenId): void
    {
        throw new LogicException('Una baja o un cambio de contrasena cierran TODAS las sesiones, no una.');
    }

    public function revokeAllFor(string $userUuid): void
    {
        $this->revokedAccounts[] = $userUuid;
    }
}
