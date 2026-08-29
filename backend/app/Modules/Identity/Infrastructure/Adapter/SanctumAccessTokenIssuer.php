<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Application\Port\AccessTokenIssuer;
use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Identity\Domain\ValueObject\IssuedAccessToken;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
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
        $expiresAt = $this->clock->now()->modify('+'.$this->sessionHours().' hours');

        return $this->issue($user, $deviceName, $user->abilityNames(), $expiresAt);
    }

    /**
     * La sesion pendiente de segundo factor (RS-06).
     *
     * **Un solo ambito y minutos de vida.** Lo que hay abierto aqui es media
     * autenticacion: si durase las doce horas de una sesion, una contrasena robada
     * se convertiria en un acceso pendiente de un unico codigo durante toda la
     * jornada. Los minutos son configuracion (regla dura 13), no una constante.
     *
     * **El nombre se conserva** para que la sesion definitiva herede el
     * `device_name` que puso el cliente al pedir entrar.
     */
    public function issuePendingFor(AuthenticatedUser $user, string $deviceName): IssuedAccessToken
    {
        $expiresAt = $this->clock->now()->modify('+'.$this->challengeMinutes().' minutes');

        return $this->issue(
            $user,
            $deviceName,
            [TokenAbility::TWO_FACTOR_PENDING->value],
            $expiresAt,
        );
    }

    /**
     * @param  list<string>  $abilities
     */
    private function issue(
        AuthenticatedUser $user,
        string $deviceName,
        array $abilities,
        \DateTimeImmutable $expiresAt,
    ): IssuedAccessToken {
        $account = User::query()->where('uuid', $user->uuid)->first();

        if (! $account instanceof User) {
            // Solo puede ocurrir si la cuenta desaparece entre la comprobacion de
            // credenciales y la emision. No se degrada a "token sin usuario":
            // eso seria una sesion sin dueno.
            throw new RuntimeException('La cuenta ha dejado de existir mientras se emitia su token.');
        }

        $token = $account->createToken($deviceName, $abilities, $expiresAt);

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

    private function challengeMinutes(): int
    {
        $minutes = config()->integer('identity.two_factor.challenge_minutes');

        return max(1, $minutes);
    }
}
