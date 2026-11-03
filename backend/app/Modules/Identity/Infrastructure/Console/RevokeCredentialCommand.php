<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Command\RevokeCredentialCommand as RevokeCredential;
use App\Modules\Identity\Application\Exception\CredentialRevocationSignerNotAllowed;
use App\Modules\Identity\Application\Support\CredentialManagers;
use App\Modules\Identity\Application\UseCase\RevokeCredential as RevokeCredentialHandler;
use App\Modules\Identity\Domain\Exception\IdentityDomainException;
use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Domain\ValueObject\ResponsibleAccount;
use App\Modules\Shared\Infrastructure\Console\ConsoleResponsible;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
use Illuminate\Console\Command;

/**
 * `php artisan credentials:revoke {credential} --reason= --responsible=` —
 * retira una tarjeta (Anexo C del doc 02, RF-QR-03).
 *
 * **El motivo es obligatorio** y no tiene valor por defecto. Un
 * `--reason="revocada"` de serie convertiria el campo en ruido, y este campo es
 * lo que explica meses despues por que una persona no pudo fichar un martes.
 *
 * **Desde la 2.3.0 la cuenta firma el asiento** (doc 01 §7.2, «Firmas por
 * consola»). Hasta la 2.2.1 el asiento `credential.revoked` decia `system`.
 * Ahora la orden enseña la cuenta (`Firma: nombre <correo> · rol · uuid`) y la
 * persona confirma que es ella —con `--force` o sin terminal, con
 * `--responsible-confirm=<correo>`—, asi que el actor es esa cuenta, como
 * cuando se revoca desde el panel. Firma quien puede revocar desde el panel:
 * `admin` o `rrhh` (`CredentialManagers`). El caso de uso vuelve a comprobar
 * la cuenta con la cadena tomada: una baja entre la pregunta y la firma no
 * revoca nada.
 *
 * A partir del `commit`, el siguiente escaneo de esa tarjeta se rechaza. Desde
 * el quiosco, de forma indistinguible de cualquier otro rechazo (RS-03).
 *
 * **Codigos de salida:** `0` revocada; `1` la credencial no existe o ya estaba
 * revocada, o la persona responde que no; `2` falta la credencial, el motivo o
 * `--responsible`, la cuenta no puede firmar (al preguntar o al firmar), o
 * falta o no cuadra `--responsible-confirm`. Con `1` o `2` no hay asiento.
 */
final class RevokeCredentialCommand extends Command
{
    use SpeaksInstallationLanguage;

    /** Seccion de `lang/*\/console.php`, compartida por las ordenes `credentials:*`. */
    private const string TEXTS = 'console.credentials';

    protected $signature = 'credentials:revoke
        {credential : UUID de la credencial}
        {--reason= : Motivo de la revocacion. Obligatorio}
        {--responsible= : Uuid o correo de la cuenta de gestion (admin o rrhh) que revoca la tarjeta y firma. Obligatorio}
        {--force : No preguntar por la firma; exige --responsible-confirm}
        {--responsible-confirm= : Correo de la cuenta de --responsible. Obligatorio con --force o sin terminal}';

    protected $description = 'Revoca una credencial QR y deja constancia del motivo y de quien la firma (RF-QR-03).';

    public function handle(RevokeCredentialHandler $handler, ResponsibleAccounts $accounts): int
    {
        $credentialUuid = trim((string) $this->argument('credential'));
        $reason = trim((string) $this->option('reason'));

        if ($credentialUuid === '') {
            $this->error($this->text('credential_required'));

            return self::INVALID;
        }

        if ($reason === '') {
            $this->error($this->text('revoke.reason_required'));

            return self::INVALID;
        }

        $responsible = $this->signedBy($accounts, $credentialUuid);

        if (\is_int($responsible)) {
            return $responsible;
        }

        try {
            $revoked = $handler->handle(new RevokeCredential(
                credentialUuid: $credentialUuid,
                reason: $reason,
                // La cuenta que ha confirmado su firma: el asiento es suyo,
                // como cuando se revoca desde el panel.
                actorUserId: $responsible->id,
                signedAtConsole: true,
            ));
        } catch (CredentialRevocationSignerNotAllowed) {
            // La cuenta se dio de baja o perdio el rol entre la pregunta y la
            // firma: no se ha revocado ni escrito nada.
            return ConsoleResponsible::noLongerAllowed($this);
        } catch (IdentityDomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($revoked === null) {
            $this->error($this->text('credential_not_found'));

            return self::FAILURE;
        }

        $this->info($this->text('revoke.revoked', ['uuid' => $revoked->credential->uuid]));
        $this->line($this->text('revoke.reason', ['reason' => (string) $revoked->credential->revokedReason]));

        return self::SUCCESS;
    }

    /**
     * La cuenta que firma la revocacion, o el codigo de salida con el motivo
     * ya escrito: `2` si falta `--responsible`, la cuenta no puede firmar o
     * falta o no cuadra `--responsible-confirm`; `1` si la persona responde
     * que no.
     */
    private function signedBy(ResponsibleAccounts $accounts, string $credentialUuid): ResponsibleAccount|int
    {
        // Los roles que revocan desde el panel (`CredentialPolicy::revoke`):
        // la consola no es la via por la que alguien hace lo que el panel le
        // niega.
        return ConsoleResponsible::sign(
            $this,
            $this->input,
            $accounts,
            CredentialManagers::ROLES,
            $this->text('revoke.confirm', ['uuid' => $credentialUuid]),
        );
    }
}
