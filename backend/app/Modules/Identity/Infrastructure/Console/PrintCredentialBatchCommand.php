<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Command\PrintCredentialBatchCommand as PrintBatch;
use App\Modules\Identity\Application\UseCase\PrintCredentialBatch as PrintBatchHandler;
use App\Modules\Identity\Domain\Exception\IdentityDomainException;
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
            $this->line('El lote es todo o nada: no se ha impreso ninguna tarjeta. Vuelve a ejecutarlo.');

            return self::FAILURE;
        }

        if ($printed->isEmpty()) {
            // No es un error: es la idempotencia del lote. Un codigo de salida
            // distinto de cero haria fallar el guion de alguien que lo ejecuta
            // por costumbre cada mañana.
            $this->info('No hay ninguna credencial pendiente de imprimir.');

            return self::SUCCESS;
        }

        if (file_put_contents($out, $printed->pdf) === false) {
            $this->error('El PDF se genero pero no se ha podido escribir en «'.$out.'».');
            $this->line('Las '.$printed->count().' credenciales constan IMPRESAS y sus tokens son irrecuperables.');
            $this->line('Sigue docs/runbooks/tarjeta-perdida-o-rota.md: revocar con motivo «impresion fallida» y reemitir.');

            return self::FAILURE;
        }

        $this->info($printed->count().' tarjeta(s) en una sola hoja: '.$out);
        $this->line('Imprime, corta por las guias y BORRA el fichero.');
        $this->line('Despues, registra cada entrega: php artisan credentials:deliver <credencial> --by=<correo>');

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
            return $this->reject('Hace falta --pending. Es la unica seleccion posible: no existe la reimpresion (ADR-034).');
        }

        $target = $this->option('out');

        if (! \is_string($target) || trim($target) === '') {
            return $this->reject('Hace falta --out=/ruta/credenciales.pdf: este comando no elige donde dejar un PDF de tarjetas.');
        }

        return null;
    }

    private function reject(string $message): string
    {
        $this->error($message);

        return $message;
    }
}
