<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Command\PrintCredentialBatchCommand as PrintBatch;
use App\Modules\Identity\Application\UseCase\PrintCredentialBatch as PrintBatchHandler;
use App\Modules\Identity\Domain\Exception\IdentityDomainException;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
use Illuminate\Console\Command;

/**
 * `php artisan credentials:print-batch --pending` — **una sola hoja A4** con
 * todas las tarjetas pendientes de la instalacion (Anexo C del doc 02, RF-QR-04,
 * ADR-040).
 *
 * El doc 02 §5.5 dice para que sirve: *«La hoja A4 con varias tarjetas por pagina
 * es lo que hace viable dar de alta a 40 personas de temporada en una tarde.»*
 *
 * ## `--pending` no lleva contrario, y eso es la garantia
 *
 * **No existe ninguna bandera que haga reimprimir** (ADR-034). La opcion se
 * declara —y se exige— para que quien escribe la orden vea que esta pidiendo solo
 * las pendientes, pero no hay alternativa: no hay `--all`, no hay `--force`, no
 * hay `--reprint`. La idempotencia del lote es exactamente esa: **la segunda
 * pasada no encuentra nada pendiente y no produce ningun PDF**. Es lo que impide
 * que dos ejecuciones del mismo lote den dos juegos de tarjetas con QR distinto
 * de los que solo el ultimo vale.
 *
 * ## Todo o nada
 *
 * Si entre la seleccion y la escritura alguien imprime una de ellas, la
 * transaccion se deshace entera y no sale ninguna tarjeta. Un lote a medias es
 * peor que ninguno: nadie sabria cuales de las sesenta de la hoja valen.
 *
 * ## El PDF va donde diga el operador
 *
 * Por lo mismo que en la impresion individual: es un instrumento al portador y el
 * runbook manda borrarlo tras imprimir.
 */
final class PrintCredentialBatchCommand extends Command
{
    use SpeaksInstallationLanguage;

    /** Seccion de `lang/*\/console.php`, compartida por las ordenes `credentials:*`. */
    private const string TEXTS = 'console.credentials';

    protected $signature = 'credentials:print-batch
        {--pending : Imprime solo las pendientes. Es la unica seleccion posible y hay que declararla}
        {--out= : Ruta del fichero PDF que se va a escribir. Obligatoria}';

    protected $description = 'Imprime en una hoja A4 todas las credenciales pendientes de la instalacion (RF-QR-04).';

    public function handle(PrintBatchHandler $handler): int
    {
        if ($this->rejectBadInput() !== null) {
            return self::INVALID;
        }

        $target = $this->option('out');
        $out = \is_string($target) ? trim($target) : '';
        try {
            $printed = $handler->handle(new PrintBatch);
        } catch (IdentityDomainException $exception) {
            $this->error($exception->getMessage());
            $this->line($this->text('print_batch.all_or_nothing'));

            return self::FAILURE;
        }

        if ($printed->isEmpty()) {
            // No es un error: es la idempotencia del lote. Un codigo de salida
            // distinto de cero haria fallar el guion de alguien que lo ejecuta
            // por costumbre cada mañana.
            $this->info($this->text('print_batch.nothing_pending'));

            return self::SUCCESS;
        }

        if (file_put_contents($out, $printed->pdf) === false) {
            $this->error($this->text('print.not_written', ['path' => $out]));
            $this->line($this->text('print_batch.printed_many', ['count' => $printed->count()]));
            $this->line($this->text('print.runbook'));

            return self::FAILURE;
        }

        $this->info($this->text('print_batch.generated', ['count' => $printed->count(), 'path' => $out]));
        $this->line($this->text('print_batch.handle_with_care'));
        $this->line($this->text('print_batch.next'));

        return self::SUCCESS;
    }

    /**
     * Las tres comprobaciones de la orden, fuera de `handle()`.
     *
     * Devuelve el mensaje ya escrito, o `null` si la orden es valida. Estan
     * juntas aqui —y no repartidas por `handle()`— porque las tres dicen lo
     * mismo: «esta orden no se puede ejecutar tal y como esta escrita», y porque
     * mezclarlas con el flujo de la impresion metia siete ramas en el metodo que
     * de verdad hace algo.
     */
    private function rejectBadInput(): ?string
    {
        if (! (bool) $this->option('pending')) {
            // Exigir la bandera no es burocracia: deja escrito en la orden —y en
            // el historial del interprete— que quien la ejecuto sabia que esto
            // NO reimprime nada.
            return $this->reject('print_batch.pending_required');
        }

        $target = $this->option('out');

        if (! \is_string($target) || trim($target) === '') {
            return $this->reject('print_batch.out_required');
        }

        return null;
    }

    private function reject(string $key): string
    {
        $message = $this->text($key);

        $this->error($message);

        return $message;
    }
}
