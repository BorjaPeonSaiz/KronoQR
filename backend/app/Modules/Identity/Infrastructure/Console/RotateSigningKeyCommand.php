<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Command\RotateSigningKeyCommand as RotateSigningKeyInput;
use App\Modules\Identity\Application\Exception\SigningKeyRotationNotReady;
use App\Modules\Identity\Application\UseCase\RotateSigningKey;
use App\Modules\Identity\Application\UseCase\SigningKeyRotationReport;
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
 * ## Y no hay endpoint, tampoco (decision de la tarea 2.12)
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
        $this->line(sprintf(
            'Clave saliente: %s · clave actual: %s',
            $report->retiringKeyId,
            $report->currentKeyId,
        ));

        $this->line(sprintf(
            'Tarjetas activas firmadas con %s: %d',
            $report->retiringKeyId,
            $report->cardsOnRetiringKey,
        ));

        if ($report->dryRun) {
            $this->info(sprintf('En seco: se reemitirian %d credenciales.', $report->reissued));

            if ($report->alreadyPending > 0) {
                $this->line(sprintf(
                    '%d ya tienen una reemision pendiente de imprimir y no se duplicarian.',
                    $report->alreadyPending,
                ));
            }

            return;
        }

        $this->info(sprintf(
            'Reemitidas %d credenciales, pendientes de imprimir. Ninguna tarjeta vigente se ha invalidado.',
            $report->reissued,
        ));

        if ($report->alreadyPending > 0) {
            $this->line(sprintf('%d ya estaban pendientes y no se han duplicado.', $report->alreadyPending));
        }

        $this->line('');
        $this->line('Siguientes pasos (docs/runbooks/rotacion-clave-qr.md):');
        $this->line('  1. php artisan credentials:print-batch --pending --out=/tmp/credenciales.pdf');
        $this->line('  2. Entregar en mano y registrar: php artisan credentials:deliver <uuid> --by=<usuario>');
        $this->line('  3. php artisan credentials:status --key-id='.$report->retiringKeyId.'   # quien falta');
        $this->line('  4. php artisan credentials:retire-key '.$report->retiringKeyId.'        # cuando no falte nadie');
    }
}
