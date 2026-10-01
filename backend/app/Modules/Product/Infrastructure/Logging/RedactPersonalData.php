<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * El *tap* que monta {@see RedactPersonalDataProcessor} en un canal de log, **el
 * ultimo de su cadena** (L1, regla dura 21).
 *
 * ## Por que un tap y no la clave `processors`
 *
 * Por lo mismo que `AddCorrelation`: `processors` solo existe para los canales
 * `monolog` y `custom`, y `single` y `daily` la ignoran. El tap corre para
 * cualquier driver. `config/logging.php` lo declara en **todos** los canales
 * que escriben a algun sitio, y `EveryLogChannelRedactsPersonalDataTest` falla
 * si uno nuevo llega sin el.
 *
 * ## Por que el ultimo, y como se consigue
 *
 * Monolog ejecuta sus processors como una pila: `pushProcessor()` mete por
 * delante y el ultimo empujado corre el primero. Un `pushProcessor()` sin mas
 * dejaria el saneado **antes** de `PsrLogMessageProcessor`, que es el que
 * interpola `{placeholders}` del contexto en el mensaje, y antes de los de
 * correlacion. Funcionaria igual —el contexto ya saneado es lo que se
 * interpolaria—, pero la garantia dependeria de ese razonamiento en lugar de
 * ser evidente: lo ultimo que toca la linea antes del handler es el saneado.
 *
 * Asi que se sacan los processors que ya hay (`popProcessor()` saca por
 * delante, en orden de ejecucion), se empuja el saneado y se vuelven a empujar
 * en orden inverso. Lo que Laravel empuje despues del tap —su
 * `ContextLogProcessor`— corre antes, que es lo correcto: solo copia el
 * `Context` a `extra`, y `extra` lo poda `CorrelationOnlyExtra`.
 *
 * En la pila (`stack`) Laravel concatena las cadenas de cada canal, de modo que
 * el saneado corre una vez por canal. Es idempotente, asi que la segunda pasada
 * no cambia nada y solo cuesta su tiempo (medido en la prueba de rendimiento).
 */
final class RedactPersonalData
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof Monolog) {
            return;
        }

        $existing = [];

        while ($monolog->getProcessors() !== []) {
            $processor = $monolog->popProcessor();

            // Si el tap se aplicara dos veces al mismo logger, el saneado
            // seguiria siendo uno y seguiria siendo el ultimo.
            if (! $processor instanceof RedactPersonalDataProcessor) {
                $existing[] = $processor;
            }
        }

        $monolog->pushProcessor(new RedactPersonalDataProcessor);

        foreach (array_reverse($existing) as $processor) {
            $monolog->pushProcessor($processor);
        }
    }
}
