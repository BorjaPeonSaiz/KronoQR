<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Command\RotateSigningKeyCommand as RotateSigningKeyInput;
use App\Modules\Identity\Application\Exception\SigningKeyRotationNotReady;
use App\Modules\Identity\Application\UseCase\RotateSigningKey;
use App\Modules\Identity\Application\UseCase\SigningKeyRotationReport;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
use Illuminate\Console\Command;

/**
 * `php artisan credentials:rotate-key` — abre la rotacion de la clave de firma
 * con solape (Anexo C del doc 02, RF-QR-07, §5.3).
 *
 * ## Antes de ejecutarlo, el operador ya ha hecho lo unico que la aplicacion no
 * hace
 *
 * ```
 * QR_SIGNING_KEY_PREVIOUS_ID=a3   QR_SIGNING_KEY_PREVIOUS=<la que habia en CURRENT>
 * QR_SIGNING_KEY_CURRENT_ID=a4    QR_SIGNING_KEY_CURRENT=<32 bytes nuevos>
 * ```
 *
 * **Este comando no genera ninguna clave y no la pide por parametro.** No la
 * genera porque el secreto no debe pasar por PHP, por la salida de una terminal
 * ni por el historial de un interprete (es la misma leccion de ADR-034 con el
 * token de la tarjeta); no la pide por parametro porque un argumento acaba en
 * `ps`, en el historial y en el registro de cualquier guion que lo llame. Lo que
 * hace es **comprobar** que la configuracion esta en estado de solape y reemitir.
 *
 * ## Y no hay endpoint, tampoco
 *
 * Rotar la clave no es una accion de panel: es un acto operativo con semanas de
 * logistica de reimpresion detras que empieza en el gestor de secretos del
 * servidor y termina cuando el ultimo empleado recibe su tarjeta. Un boton que
 * lo dispare invita a pulsarlo. El panel solo **lee**:
 * `GET /credentials/status?key_id=` dice a quien le falta.
 *
 * El procedimiento completo esta en `docs/runbooks/rotacion-clave-qr.md`.
 */
final class RotateSigningKeyCommand extends Command
{
    use SpeaksInstallationLanguage;

    /** Seccion de `lang/*\/console.php`, compartida por las ordenes `credentials:*`. */
    private const string TEXTS = 'console.credentials';

    protected $signature = 'credentials:rotate-key
        {--dry-run : Solo informa: no reemite ni escribe nada}';

    protected $description = 'Abre la rotacion de la clave de firma y reemite las tarjetas pendientes de reimprimir (RF-QR-07).';

    public function handle(RotateSigningKey $handler): int
    {
        try {
            $report = $handler->handle(new RotateSigningKeyInput(
                dryRun: (bool) $this->option('dry-run'),
            ));
        } catch (SigningKeyRotationNotReady $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->render($report);

        return self::SUCCESS;
    }

    private function render(SigningKeyRotationReport $report): void
    {
        $this->line($this->text('rotate_key.keys', [
            'retiring' => $report->retiringKeyId,
            'current' => $report->currentKeyId,
        ]));

        $this->line($this->text('rotate_key.cards', [
            'retiring' => $report->retiringKeyId,
            'count' => $report->cardsOnRetiringKey,
        ]));

        if ($report->dryRun) {
            $this->info($this->text('rotate_key.dry_run', ['count' => $report->reissued]));

            if ($report->alreadyPending > 0) {
                $this->line($this->text('rotate_key.dry_run_pending', ['count' => $report->alreadyPending]));
            }

            return;
        }

        $this->info($this->text('rotate_key.reissued', ['count' => $report->reissued]));

        if ($report->alreadyPending > 0) {
            $this->line($this->text('rotate_key.already_pending', ['count' => $report->alreadyPending]));
        }

        $this->line('');
        $this->paragraph('rotate_key.next_steps', ['key' => $report->retiringKeyId]);
    }
}
