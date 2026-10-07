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

        // RF-ID-10, ADR-051: con contrasena temporal, la sesion solo sirve para
        // cambiarla. Aqui y solo aqui: es el unico sitio por el que sale una
        // sesion de gestion, asi que ningun camino de acceso puede olvidarlo.
        $abilities = $user->passwordChangeRequired()
            ? [TokenAbility::PASSWORD_CHANGE->value]
            : $user->abilityNames();

        return $this->issue($user, $deviceName, $abilities, $expiresAt);
    }

    public function revokeAllExcept(string $userUuid, int|string $keepTokenId): void
    {
        $account = User::query()->where('uuid', $userUuid)->first();

        if (! $account instanceof User) {
            return;
        }

        Sanctum::personalAccessTokenModel()::query()
            ->where('tokenable_type', $account->getMorphClass())
            ->where('tokenable_id', $account->getKey())
            ->whereKeyNot($keepTokenId)
            ->delete();
    }

    public function promoteToFullSession(AuthenticatedUser $user, int|string $tokenId): bool
    {
        $account = User::query()->where('uuid', $user->uuid)->first();

        if (! $account instanceof User) {
            return false;
        }

        // `update()` sobre la consulta, y no `save()` sobre el modelo: el
        // recuento de filas es lo que dice si el token sigue existiendo. La
        // columna es JSON y el constructor de consultas no aplica el cast.
        $updated = Sanctum::personalAccessTokenModel()::query()
            ->whereKey($tokenId)
            ->where('tokenable_type', $account->getMorphClass())
            ->where('tokenable_id', $account->getKey())
            ->update(['abilities' => json_encode($user->abilityNames(), JSON_THROW_ON_ERROR)]);

        return $updated === 1;
    }

    /**
     * Los retos abiertos de una cuenta: **como mucho uno, y el ultimo**.
     *
     * ## Por que se limpian y no se dejan caducar solos
     *
     * Un reto vale diez minutos, y en ese hueco cada intento de acceso con la
     * contrasena correcta dejaba **un token vivo mas**. Tres consecuencias, todas
     * reales:
     *
     * 1. **Media autenticacion acumulada.** Quien tiene la contrasena de alguien
     *    —que es exactamente el escenario contra el que RS-06 pone el segundo
     *    factor— podia sembrar tantos retos como quisiera y quedarse esperando a
     *    que la victima cantara un codigo en voz alta, con varias oportunidades
     *    abiertas en lugar de una.
     * 2. **El «cancelar» del panel no cerraba nada.** Cerrar la pantalla del
     *    codigo abandona el token; entrar otra vez abria otro y el anterior seguia
     *    valiendo hasta caducar.
     * 3. Filas de `personal_access_tokens` que nadie iba a canjear.
     *
     * Emitir un reto **invalida el anterior**, y emitir la sesion definitiva
     * invalida los que queden: al terminar de entrar no puede sobrevivir ningun
     * permiso de «presenta un codigo por esta cuenta». Es el mismo criterio con el
     * que `VerifyTwoFactorHandler` consume el reto con el que se le llamo, extendido
     * a los que ese handler no ve porque se abrieron en otra pestaña.
     *
     * **Solo los retos.** No toca ninguna sesion de verdad: entrar en el portatil
     * no puede echar a nadie de la tablet donde estaba revisando incidencias, que
     * es la razon por la que {@see self::revoke()} revoca uno y no todos.
     */
    private function revokePendingChallengesOf(User $account): void
    {
        $onlyPending = [TokenAbility::TWO_FACTOR_PENDING->value];

        $tokens = Sanctum::personalAccessTokenModel()::query()
            ->where('tokenable_type', $account->getMorphClass())
            ->where('tokenable_id', $account->getKey())
            ->get();

        foreach ($tokens as $token) {
            // Se compara la lista COMPLETA de ambitos y no se pregunta «¿puede
            // presentar un codigo?»: lo segundo alcanzaria tambien a un token que
            // llevara ese ambito entre otros, y borrar una sesion por parecerse a
            // un reto es peor que dejar un reto vivo.
            if ($token->abilities === $onlyPending) {
                $token->delete();
            }
        }
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

        // ANTES DE EMITIR, EN LOS DOS CAMINOS. Al abrir un reto nuevo se cierra el
        // anterior; al emitir la sesion definitiva no puede quedar vivo ninguno.
        $this->revokePendingChallengesOf($account);

        $token = $account->createToken($deviceName, $abilities, $expiresAt);

        return new IssuedAccessToken($token->plainTextToken, $expiresAt);
    }

    public function revoke(int|string $tokenId): void
    {
        Sanctum::personalAccessTokenModel()::query()
            ->whereKey($tokenId)
            ->delete();
    }

    public function revokeAllFor(string $userUuid): void
    {
        $account = User::query()->where('uuid', $userUuid)->first();

        if (! $account instanceof User) {
            // Sin cuenta no hay tokens que revocar. No se lanza: quien llama
            // —la baja y los dos restablecimientos— acaba de bloquear la fila
            // de la cuenta, asi que esto no se alcanza; y si se alcanzara, un
            // `500` no revocaria nada mas.
            return;
        }

        Sanctum::personalAccessTokenModel()::query()
            ->where('tokenable_type', $account->getMorphClass())
            ->where('tokenable_id', $account->getKey())
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
