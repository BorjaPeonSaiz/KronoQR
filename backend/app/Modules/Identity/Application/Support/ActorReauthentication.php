<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Support;

use App\Modules\Identity\Application\Command\ActorProof;
use App\Modules\Identity\Application\Exception\AccountTemporarilyLocked;
use App\Modules\Identity\Application\Exception\ActorReauthenticationFailed;
use App\Modules\Identity\Application\Port\LoginAttempts;
use App\Modules\Identity\Application\Port\ManagementAccountLifecycle;
use App\Modules\Identity\Application\Port\PasswordHasher;
use App\Modules\Identity\Application\Port\TwoFactorAuthenticator;
use App\Modules\Identity\Application\Port\TwoFactorSecrets;
use App\Modules\Shared\Application\Port\AuthenticationJournal;
use App\Modules\Shared\Domain\ValueObject\AuthChannel;

/**
 * **Reautenticacion de quien actua sobre otra cuenta** (RF-ID-10, ASVS V3.7.1).
 *
 * El alta de una cuenta y el restablecimiento de la contrasena o del segundo
 * factor de otra persona son las tres operaciones con las que una sesion de
 * `admin` robada —un portatil desbloqueado en recepcion— se convierte en
 * apropiacion de cuentas y en persistencia. Una sesion abierta no basta: se
 * pide el codigo del autenticador del `admin` que actua y, si su cuenta no
 * tiene segundo factor confirmado, su contrasena.
 *
 * **Comparte el contador de intentos de codigo con `/auth/2fa/*`** (clave
 * `2fa|<uuid>`), y la marca de la ultima franja aceptada: un codigo usado para
 * entrar no vale otra vez aqui, y alternar las dos puertas no duplica los
 * intentos. Con el bloqueo abierto, `429`.
 *
 * Se ejecuta **fuera de cualquier candado**: verificar un TOTP o un hash cuesta,
 * y lo que escribe —la franja aceptada— es una fila de la propia cuenta del
 * actor en autocommit.
 */
final readonly class ActorReauthentication
{
    public const string FIELD_TOTP = 'actor_totp_code';

    public const string FIELD_PASSWORD = 'actor_current_password';

    public function __construct(
        private TwoFactorSecrets $secrets,
        private TwoFactorAuthenticator $authenticator,
        private ManagementAccountLifecycle $accounts,
        private PasswordHasher $hasher,
        private LoginAttempts $attempts,
        private AuthenticationJournal $journal,
    ) {}

    /**
     * @throws AccountTemporarilyLocked con el bloqueo de intentos de codigo abierto
     * @throws ActorReauthenticationFailed si la prueba falta o no vale
     */
    public function confirm(string $actorUuid, ActorProof $proof): void
    {
        $key = '2fa|'.$actorUuid;

        if ($this->attempts->isLocked($key)) {
            throw new AccountTemporarilyLocked($this->attempts->secondsUntilUnlock($key));
        }

        $secret = $this->secrets->activeSecretFor($actorUuid);

        if ($secret !== null) {
            $this->confirmCode($actorUuid, $secret, $proof, $key);

            return;
        }

        $this->confirmPassword($actorUuid, $proof, $key);
    }

    /**
     * Con segundo factor confirmado solo vale el codigo. Si llego la contrasena
     * en su lugar, el error va en ese campo (el contrato: «con segundo factor,
     * este campo responde `422`»). Un campo que falta no es un intento y no
     * cuenta para el bloqueo.
     */
    private function confirmCode(string $actorUuid, string $secret, ActorProof $proof, string $key): void
    {
        $code = $proof->totpCode;

        if ($code === null || $code === '') {
            throw new ActorReauthenticationFailed(
                $proof->currentPassword !== null && $proof->currentPassword !== '' ? self::FIELD_PASSWORD : self::FIELD_TOTP,
            );
        }

        $slice = $this->authenticator->verify($secret, $code, $this->secrets->lastAcceptedSliceFor($actorUuid));

        // La franja se gasta con una escritura condicionada: de dos peticiones
        // con el mismo codigo solo vale una, y la otra cuenta como fallo.
        if ($slice === null || ! $this->secrets->rememberAcceptedSlice($actorUuid, $slice)) {
            $this->fail($actorUuid, $key, self::FIELD_TOTP);
        }

        $this->attempts->clear($key);
    }

    /**
     * Sin segundo factor confirmado, la contrasena. Un codigo enviado en su
     * lugar no puede valer —no hay secreto contra el que comprobarlo— y el error
     * va en ese campo.
     */
    private function confirmPassword(string $actorUuid, ActorProof $proof, string $key): void
    {
        $password = $proof->currentPassword;

        if ($password === null || $password === '') {
            throw new ActorReauthenticationFailed(
                $proof->totpCode !== null && $proof->totpCode !== '' ? self::FIELD_TOTP : self::FIELD_PASSWORD,
            );
        }

        $hash = $this->accounts->currentPasswordHash($actorUuid);

        if ($hash === null || ! $this->hasher->matches($password, $hash)) {
            $this->fail($actorUuid, $key, self::FIELD_PASSWORD);
        }

        $this->attempts->clear($key);
    }

    private function fail(string $actorUuid, string $key, string $field): never
    {
        $this->attempts->recordFailure($key);

        if ($this->attempts->isLocked($key)) {
            $this->journal->lockoutStarted(
                AuthChannel::MANAGEMENT,
                $actorUuid,
                $this->attempts->secondsUntilUnlock($key),
            );
        }

        throw new ActorReauthenticationFailed($field);
    }
}
