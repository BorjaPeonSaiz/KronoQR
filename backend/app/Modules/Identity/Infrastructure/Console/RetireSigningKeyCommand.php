<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Command\RetireSigningKeyCommand as RetireSigningKeyInput;
use App\Modules\Identity\Application\Exception\SigningKeyStillInUse;
use App\Modules\Identity\Application\UseCase\RetireSigningKey;
use Illuminate\Console\Command;

/**
 * `php artisan credentials:retire-key {key_id}` — cierra el solape de una
 * rotacion (Anexo C del doc 02, RF-QR-07, §5.3).
 *
 * **Se niega mientras quede una sola tarjeta activa firmada con esa clave**, y
 * dice cuantas y en que centro. No es una advertencia con confirmacion: retirar
 * la clave hace que sus firmas dejen de verificar, y quien lleve una de esas
 * tarjetas se planta el lunes delante del quiosco con un rechazo que —por RS-03,
 * y correctamente— no le explica nada.
 *
 * **No toca la configuracion.** Vaciar `QR_SIGNING_KEY_PREVIOUS` es del
 * operador, en el gestor de secretos del servidor (regla dura 13). Lo que este
 * comando hace es **certificar** que ya se puede, y dejarlo escrito en
 * `audit_log` con `signing_key.retired`.
 */
final class RetireSigningKeyCommand extends Command
{
    protected $signature = 'credentials:retire-key
        {key_id : Identificador de la clave saliente, dos caracteres (por ejemplo a3)}';

    protected $description = 'Certifica que la clave de firma saliente ya no firma ninguna tarjeta viva (RF-QR-07).';

    public function handle(RetireSigningKey $handler): int
    {
        $keyId = trim((string) $this->argument('key_id'));

        if ($keyId === '') {
            $this->error('Hace falta el key_id de la clave que se retira.');

            return self::INVALID;
        }

        try {
            $report = $handler->handle(new RetireSigningKeyInput(keyId: $keyId));
        } catch (SigningKeyStillInUse $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'La clave %s ya no firma ninguna credencial activa (%d tarjetas en total a lo largo de su vida).',
            $report->keyId,
            $report->signedCredentials,
        ));

        $this->line('');
        $this->line('Ultimo paso, en el servidor:');
        $this->line('  - Vacia QR_SIGNING_KEY_PREVIOUS_ID y QR_SIGNING_KEY_PREVIOUS.');
        $this->line('  - Reinicia la aplicacion y comprueba que se ficha con normalidad.');
        $this->line('  - Destruye la copia de la clave retirada del gestor de secretos.');

        return self::SUCCESS;
    }
}
