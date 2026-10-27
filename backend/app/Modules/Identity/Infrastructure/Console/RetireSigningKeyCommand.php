<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Command\RetireSigningKeyCommand as RetireSigningKeyInput;
use App\Modules\Identity\Application\Exception\SigningKeyStillInUse;
use App\Modules\Identity\Application\UseCase\RetireSigningKey;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
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
    use SpeaksInstallationLanguage;

    /** Seccion de `lang/*\/console.php`, compartida por las ordenes `credentials:*`. */
    private const string TEXTS = 'console.credentials';

    protected $signature = 'credentials:retire-key
        {key_id : Identificador de la clave saliente, dos caracteres (por ejemplo a3)}';

    protected $description = 'Certifica que la clave de firma saliente ya no firma ninguna tarjeta viva (RF-QR-07).';

    public function handle(RetireSigningKey $handler): int
    {
        $keyId = trim((string) $this->argument('key_id'));

        if ($keyId === '') {
            $this->error($this->text('retire_key.key_required'));

            return self::INVALID;
        }

        try {
            $report = $handler->handle(new RetireSigningKeyInput(keyId: $keyId));
        } catch (SigningKeyStillInUse $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($this->text('retire_key.retired', [
            'key' => $report->keyId,
            'count' => $report->signedCredentials,
        ]));

        $this->line('');
        $this->paragraph('retire_key.last_step');

        return self::SUCCESS;
    }
}
