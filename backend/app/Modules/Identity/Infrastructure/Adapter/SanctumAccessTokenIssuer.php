<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Application\Port\AccessTokenIssuer;
use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Identity\Domain\ValueObject\IssuedAccessToken;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Application\Port\Clock;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

/**
 * Emision de tokens de sesion sobre Laravel Sanctum (doc 02 §3.1).
 *
 * **La caducidad se pasa token a token y no se deja en la configuracion global
 * de Sanctum.** El producto emite dos clases de token con vidas muy distintas:
 * la sesion de gestion es corta (§7.3) y el token del quiosco dura 90 dias con
 * rotacion automatica (RF-ID-04). Con una caducidad global, poner la de gestion
 * dejaria a los quioscos desconectandose cada pocas horas, y poner la del
 * quiosco convertiria una sesion de panel olvidada en un acceso de tres meses.
 *
 * **El instante lo da el puerto `Clock`** (ADR-021, regla dura 2), no `now()`:
 * es lo que permite probar la caducidad sin esperar.
 */
final readonly class SanctumAccessTokenIssuer implements AccessTokenIssuer
{
    public function __construct(private Clock $clock) {}

    public function issueFor(AuthenticatedUser $user, string $deviceName): IssuedAccessToken
    {
        $account = User::query()->where('uuid', $user->uuid)->first();

        if (! $account instanceof User) {
            // Solo puede ocurrir si la cuenta desaparece entre la comprobacion de
            // credenciales y la emision. No se degrada a "token sin usuario":
            // eso seria una sesion sin dueno.
            throw new RuntimeException('La cuenta ha dejado de existir mientras se emitia su token.');
        }

        $expiresAt = $this->clock->now()->modify('+'.$this->sessionHours().' hours');

        $token = $account->createToken($deviceName, $user->abilityNames(), $expiresAt);

        return new IssuedAccessToken($token->plainTextToken, $expiresAt);
    }

    public function revoke(int|string $tokenId): void
    {
        Sanctum::personalAccessTokenModel()::query()
            ->whereKey($tokenId)
            ->delete();
    }

    private function sessionHours(): int
    {
        $hours = config()->integer('identity.session.token_hours');

        return max(1, $hours);
    }
}
