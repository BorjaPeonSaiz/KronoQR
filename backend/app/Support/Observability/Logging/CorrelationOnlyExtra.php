<?php

declare(strict_types=1);

namespace App\Support\Observability\Logging;

use App\Http\Middleware\PropagateTraceContext;
use App\Support\Observability\Tracing\TraceContext;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * En `extra` solo caben los CINCO identificadores del §8.1. Todo lo demas se
 * descarta (doc 01 §9.4, regla dura 21).
 *
 * ## El fallo que esto cierra
 *
 * {@see CorrelationProcessor} prometia en su docblock que el log tecnico lleva
 * *«cuatro claves y son identificadores opacos»*, y era mentira a medias: la
 * promesa valia para lo que ESE processor anade, no para lo que llega a la
 * linea. `Illuminate\Log\Context\ContextLogProcessor` —del framework, y se
 * empuja **despues** del tap, asi que corre **antes**— copia
 * `Context::all()` ENTERO a `extra`, y el `LokiHandler` serializa `extra` tal
 * cual. Cualquier `Context::add('employee_name', …)` escrito en cualquier punto
 * del producto —o por un paquete de terceros— acababa en Loki y se quedaba ahi
 * los 90 dias de la retencion (RL-11).
 *
 * El `Context` de Laravel es un canal de proposito general: sirve para arrastrar
 * lo que sea a traves de una peticion y de un job. Que ese canal desemboque sin
 * filtro en el log tecnico es lo que convierte «no escribas nombres en el log»
 * en una norma que nadie puede verificar. Con este processor la norma la aplica
 * el codigo: lo que no este en la lista no sale, lo escriba quien lo escriba.
 *
 * ## Es una LISTA DE PERMITIDOS, y por eso son cinco y no «todo menos»
 *
 * Mismo criterio que `FieldAllowlist` en el paquete de diagnostico (ADR-020):
 * una lista de prohibidos deja pasar lo que nadie penso en prohibir, que es
 * exactamente el dato nuevo que alguien anade manana.
 *
 * `traceparent` esta en la lista **ya validado**: {@see PropagateTraceContext}
 * solo lo publica si casa con la forma del W3C.
 *
 * ## Lo que NO toca
 *
 * `context` (el array que pasa quien escribe el apunte) y `message`. Ahi el dato
 * lo pone quien escribe la linea, a la vista en su propio fichero y bajo la
 * revision de `TechnicalLogHasNoPersonalDataTest`. `extra` es distinto: nadie lo
 * escribe a proposito y su contenido depende de lo que otro dejara en el
 * `Context` tres capas mas arriba.
 *
 * ## Orden
 *
 * Lo empuja {@see AddCorrelation} **despues** de {@see CorrelationProcessor}, de
 * modo que en la cadena de Monolog —que es una pila: el ultimo empujado corre el
 * primero— quede: `ContextLogProcessor` (copia el `Context`) -> este (poda) ->
 * `CorrelationProcessor` (rellena los identificadores que falten). Podar antes
 * de rellenar es lo correcto: lo que rellena el ultimo ya esta en la lista.
 *
 * ## No puede romper un registro
 *
 * Ante cualquier problema se devuelve el registro tal cual entro (regla dura 19).
 */
final readonly class CorrelationOnlyExtra implements ProcessorInterface
{
    /**
     * Las cinco unicas claves que sobreviven en `extra`.
     *
     * @var list<string>
     */
    public const array ALLOWED = [
        TraceContext::TRACE_ID_KEY,
        TraceContext::TRACEPARENT_KEY,
        ...CorrelationProcessor::CORRELATION_KEYS,
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        try {
            $kept = [];

            foreach (self::ALLOWED as $key) {
                if (array_key_exists($key, $record->extra)) {
                    $kept[$key] = $record->extra[$key];
                }
            }

            if ($kept === $record->extra) {
                return $record;
            }

            return $record->with(extra: $kept);
        } catch (Throwable) {
            return $record;
        }
    }
}
