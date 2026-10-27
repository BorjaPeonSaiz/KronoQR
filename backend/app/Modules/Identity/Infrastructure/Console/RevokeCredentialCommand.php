<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Command\RevokeCredentialCommand as RevokeCredential;
use App\Modules\Identity\Application\UseCase\RevokeCredential as RevokeCredentialHandler;
use App\Modules\Identity\Domain\Exception\IdentityDomainException;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
use Illuminate\Console\Command;

/**
 * `php artisan credentials:revoke {credential} --reason=` — retira una tarjeta
 * (Anexo C del doc 02, RF-QR-03).
 *
 * **El motivo es obligatorio** y no tiene valor por defecto. Un
 * `--reason="revocada"` de serie convertiria el campo en ruido, y este campo es
 * lo que explica meses despues por que una persona no pudo fichar un martes.
 *
 * A partir del `commit`, el siguiente escaneo de esa tarjeta se rechaza. Desde
 * el quiosco, de forma indistinguible de cualquier otro rechazo (RS-03).
 */
final class RevokeCredentialCommand extends Command
{
    use SpeaksInstallationLanguage;

    /** Seccion de `lang/*\/console.php`, compartida por las ordenes `credentials:*`. */
    private const string TEXTS = 'console.credentials';

    protected $signature = 'credentials:revoke
        {credential : UUID de la credencial}
        {--reason= : Motivo de la revocacion. Obligatorio}';

    protected $description = 'Revoca una credencial QR y deja constancia del motivo (RF-QR-03).';

    public function handle(RevokeCredentialHandler $handler): int
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

        try {
            $revoked = $handler->handle(new RevokeCredential(
                credentialUuid: $credentialUuid,
                reason: $reason,
            ));
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
}
