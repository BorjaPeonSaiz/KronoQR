<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Command\RevokeCredentialCommand as RevokeCredential;
use App\Modules\Identity\Application\UseCase\RevokeCredential as RevokeCredentialHandler;
use App\Modules\Identity\Domain\Exception\IdentityDomainException;
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
    protected $signature = 'credentials:revoke
        {credential : UUID de la credencial}
        {--reason= : Motivo de la revocacion. Obligatorio}';

    protected $description = 'Revoca una credencial QR y deja constancia del motivo (RF-QR-03).';

    public function handle(RevokeCredentialHandler $handler): int
    {
        $credentialUuid = trim((string) $this->argument('credential'));
        $reason = trim((string) $this->option('reason'));

        if ($credentialUuid === '') {
            $this->error('Hace falta el UUID de la credencial.');

            return self::INVALID;
        }

        if ($reason === '') {
            $this->error('Una revocacion tiene que declarar su motivo: --reason="perdida".');

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
            $this->error('No hay ninguna credencial con ese UUID.');

            return self::FAILURE;
        }

        $this->info('Credencial revocada: '.$revoked->credential->uuid);
        $this->line('Motivo: '.(string) $revoked->credential->revokedReason);

        return self::SUCCESS;
    }
}
