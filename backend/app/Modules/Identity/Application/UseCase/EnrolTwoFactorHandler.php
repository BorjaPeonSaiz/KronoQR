<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\EnrolTwoFactorCommand;
use App\Modules\Identity\Application\Exception\AccountTemporarilyLocked;
use App\Modules\Identity\Application\Exception\AuthenticationFailed;
use App\Modules\Identity\Application\Exception\TwoFactorAlreadyEnabled;
use App\Modules\Identity\Application\Port\LoginAttempts;
use App\Modules\Identity\Application\Port\TwoFactorAuthenticator;
use App\Modules\Identity\Application\Port\TwoFactorSecrets;
use App\Modules\Identity\Application\Port\UserAccounts;
use App\Modules\Identity\Domain\ValueObject\TwoFactorEnrolment;

/**
 * Alta del segundo factor de una cuenta de gestion (**RS-06**, `POST
 * /api/v1/auth/2fa/enrol`).
 *
 * Genera el secreto, lo guarda **sin confirmar** y devuelve lo que el panel
 * necesita para pintar el QR. No activa nada: activar es
 * {@see ConfirmTwoFactorHandler}, que exige un codigo del telefono. Entre los dos
 * pasos la cuenta sigue sin poder entrar, que es lo correcto — si el alta activara
 * el TOTP por si sola, un QR mal escaneado dejaria a alguien fuera de su cuenta
 * sin ninguna forma de volver.
 *
 * **Repetir el alta es legitimo y sustituye al secreto anterior sin confirmar.**
 * Es lo que ocurre cuando alguien cierra la pantalla del QR antes de escanearlo.
 * Lo que **no** hace es tocar un secreto ya confirmado: eso es
 * {@see TwoFactorAlreadyEnabled}.
 *
 * **No hay transaccion ni evento.** Aqui no ha pasado nada auditable: se ha
 * generado un secreto que todavia no autoriza nada. El asiento de `audit_log` va
 * en la confirmacion, que es cuando la credencial empieza a existir.
 *
 * ## El alta respeta el bloqueo por intentos de codigo
 *
 * Es el **mismo contador** que {@see VerifyTwoFactorHandler} y
 * {@see ConfirmTwoFactorHandler}, y comprobarlo aqui cierra dos huecos que existen
 * porque este endpoint se alcanza con la contrasena y nada mas:
 *
 * 1. **La salida del bloqueo.** Sin esta comprobacion, quien agota los intentos de
 *    codigo pide un secreto nuevo y sigue probando; el bloqueo solo frenaria a
 *    quien no supiera que existe este endpoint.
 * 2. **La rotacion a ciegas.** Cada alta sustituye al secreto sin confirmar
 *    anterior, asi que repetirla en bucle es una escritura sobre `users` por
 *    peticion y deja a quien de verdad estaba escaneando su QR con un secreto que
 *    ya no vale.
 */
final readonly class EnrolTwoFactorHandler
{
    public function __construct(
        private UserAccounts $accounts,
        private TwoFactorSecrets $secrets,
        private TwoFactorAuthenticator $authenticator,
        private LoginAttempts $attempts,
    ) {}

    /**
     * @throws AccountTemporarilyLocked cuando se agotaron los intentos de codigo
     * @throws AuthenticationFailed si la cuenta se desactivo con el reto abierto
     * @throws TwoFactorAlreadyEnabled si ya tiene un segundo factor activo
     */
    public function handle(EnrolTwoFactorCommand $command): TwoFactorEnrolment
    {
        // Lo PRIMERO, igual que en la verificacion y en la confirmacion: si la
        // cuenta esta bloqueada, no se lee nada ni se genera nada.
        if ($this->attempts->isLocked($command->throttleKey)) {
            throw new AccountTemporarilyLocked($this->attempts->secondsUntilUnlock($command->throttleKey));
        }

        $user = $this->accounts->findByUuid($command->userUuid);

        if ($user === null) {
            throw new AuthenticationFailed;
        }

        if ($this->secrets->activeSecretFor($command->userUuid) !== null) {
            throw new TwoFactorAlreadyEnabled;
        }

        $secret = $this->authenticator->generateSecret();

        $this->secrets->storeUnconfirmedSecret($command->userUuid, $secret);

        // La etiqueta del autenticador es el correo de la cuenta: es lo que
        // distingue esta entrada de las demas en el telefono de quien tiene varias
        // instalaciones delante. No sale de aqui hacia ningun log.
        return new TwoFactorEnrolment(
            $secret,
            $this->authenticator->otpauthUriFor($user->email, $secret),
        );
    }
}
