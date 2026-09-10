<?php

declare(strict_types=1);

namespace App\Support\Observability\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * El *tap* que engancha los dos processors de correlacion a un canal de log
 * (`config/logging.php`, decision 9 de la ficha 3.1).
 *
 * ## Por que un tap y no la clave `processors`
 *
 * Porque `processors` solo existe para los canales de tipo `monolog` y `custom`.
 * Los canales `single` y `daily` —los de desarrollo y los de la suite— la
 * ignoran, y un processor que solo funciona en produccion es un processor que
 * nadie ve fallar. El tap corre para **cualquier** driver, que es lo que hace que
 * la correlacion sea una propiedad del log del producto y no de un despliegue
 * concreto.
 *
 * ## Son dos, y el orden importa
 *
 * Monolog ejecuta sus processors como una PILA: el ultimo empujado corre el
 * primero. Laravel empuja `ContextLogProcessor` —que copia `Context::all()`
 * entero a `extra`— **despues** de aplicar los taps, asi que corre antes que
 * todo lo de aqui. La cadena efectiva queda:
 *
 *   1. `ContextLogProcessor` (framework): vuelca el `Context` en `extra`.
 *   2. {@see CorrelationOnlyExtra}: poda `extra` a los cinco identificadores.
 *   3. {@see CorrelationProcessor}: rellena los que falten y resuelve `trace_id`.
 *
 * De ahi que se empuje primero el que tiene que correr el ultimo. Podar antes de
 * rellenar es lo correcto: lo que rellena el ultimo ya esta en la lista de
 * permitidos.
 */
final class AddCorrelation
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new CorrelationProcessor);
            $monolog->pushProcessor(new CorrelationOnlyExtra);
        }
    }
}
