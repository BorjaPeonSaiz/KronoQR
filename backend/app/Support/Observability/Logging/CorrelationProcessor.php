<?php

declare(strict_types=1);

namespace App\Support\Observability\Logging;

use App\Modules\Shared\Application\Support\TraceparentHeader;
use App\Support\Observability\Tracing\TraceContext;
use Closure;
use Illuminate\Support\Facades\Context;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * `trace_id`, `scan_id`, `device_id` y `employee_uuid` en **todas** las lineas
 * del log tecnico (doc 02 §8.1, doc 01 §9.4, decision 9 de la ficha 3.1).
 *
 * ## Por que un processor y no seguir poniendolo a mano
 *
 * Las dieciocho clases `*Telemetry` ya escriben esos campos en el contexto de sus
 * propios apuntes, y siguen siendo la fuente: son las que **saben** el `scan_id`
 * y el `employee_uuid`. Lo que no cubren es todo lo demas: un `Log::warning` de un
 * controlador, el informe de una excepcion no controlada, la linea que escribe un
 * trabajo de la cola. Esas salian sin nada con que unirlas al fichaje que las
 * provoco, que es justo cuando se necesitan.
 *
 * Con el processor, el punto que conoce el dato lo deja **una vez** en el
 * `Context` de Laravel y toda linea posterior de esa peticion —o del trabajo que
 * la peticion encolo, porque el `Context` viaja con el— lo lleva.
 *
 * ## De donde sale el `trace_id`, en orden
 *
 * 1. **El del propio apunte**, si quien lo escribio ya lo puso. Es el mas preciso:
 *    lo pone la telemetria del acto con el span que midio ese acto.
 * 2. **El del span activo**, cuando hay SDK configurado.
 * 3. **El del `Context`**, que dejo `PropagateTraceContext` al entrar la peticion.
 * 4. **El del `traceparent` que envio el cliente**, del que se extrae a mano.
 *
 * Los pasos 3 y 4 son los que hacen que esto funcione en **la instalacion que no
 * exporta trazas**, que es la mayoria: sin SDK no hay span, pero el quiosco envia
 * su `traceparent` en cada `fetch` y con el se puede seguir una peticion entera
 * por el log. Un identificador a ceros —el que devuelve un span inerte— no cuenta
 * como identificador: parece uno y nadie lo buscaria dos veces.
 *
 * ## Lo que NUNCA anade — y quien garantiza lo que no sale
 *
 * Este processor solo anade las cuatro claves de arriba: nombres, correos y
 * documentos no estan entre ellas (regla dura 21). Pero **eso no basta para
 * afirmar que la linea no los lleva**, y durante un tiempo este docblock lo
 * afirmaba de todas formas: `Illuminate\Log\Context\ContextLogProcessor`, del
 * framework, copia `Context::all()` ENTERO a `extra` antes de que este corra, y
 * el `LokiHandler` serializa `extra` tal cual. Cualquier
 * `Context::add('employee_name', …)` de cualquier punto del producto llegaba a
 * Loki y se quedaba los 90 dias de la retencion.
 *
 * La garantia la da {@see CorrelationOnlyExtra}, que corre entre los dos y poda
 * `extra` a los cinco identificadores del §8.1. Lo que este processor promete es
 * mas modesto y ahora es cierto: **de lo que anade, nada identifica a nadie**.
 *
 * ## No puede romper un registro
 *
 * Un processor que lanza deja la linea sin escribir, y el sitio donde eso duele
 * es el informe de la excepcion que se estaba intentando registrar. Ante
 * cualquier problema se devuelve el registro tal cual entro.
 */
final readonly class CorrelationProcessor implements ProcessorInterface
{
    /**
     * Los identificadores del §8.1, sin el `trace_id`, que tiene sus propias
     * fuentes.
     *
     * @var list<string>
     */
    public const array CORRELATION_KEYS = ['scan_id', 'device_id', 'employee_uuid'];

    /**
     * @param  (Closure(): array<string, mixed>)|null  $ambient  El contexto de la peticion. Se inyecta en las pruebas; en produccion es el `Context` de Laravel.
     */
    public function __construct(private ?Closure $ambient = null) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        try {
            $ambient = $this->ambient();
            $extra = $record->extra;

            $traceId = $this->traceIdOf($record, $ambient);

            if ($traceId !== null) {
                $extra[TraceContext::TRACE_ID_KEY] = $traceId;
            }

            foreach (self::CORRELATION_KEYS as $key) {
                // Lo que el propio apunte ya trae manda: quien lo escribio sabia
                // de que hablaba. El `Context` solo rellena lo que falta.
                if ($this->filled($record->context[$key] ?? null) || $this->filled($extra[$key] ?? null)) {
                    continue;
                }

                $value = $ambient[$key] ?? null;

                if ($this->filled($value)) {
                    $extra[$key] = $value;
                }
            }

            return $record->with(extra: $extra);
        } catch (Throwable) {
            return $record;
        }
    }

    /**
     * @param  array<string, mixed>  $ambient
     */
    private function traceIdOf(LogRecord $record, array $ambient): ?string
    {
        // Una sola normalizacion del identificador «significativo» en todo el
        // producto: la de `TraceparentHeader`. Aqui habia una cuarta copia que
        // ademas recortaba distinto que las otras tres.
        $own = TraceparentHeader::significant(
            $record->context[TraceContext::TRACE_ID_KEY] ?? $record->extra[TraceContext::TRACE_ID_KEY] ?? null,
        );

        if ($own !== null) {
            return $own;
        }

        return TraceContext::currentTraceId()
            ?? TraceparentHeader::significant($ambient[TraceContext::TRACE_ID_KEY] ?? null)
            ?? TraceparentHeader::traceIdOf($ambient[TraceContext::TRACEPARENT_KEY] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function ambient(): array
    {
        if ($this->ambient instanceof Closure) {
            return ($this->ambient)();
        }

        try {
            return Context::all();
        } catch (Throwable) {
            // Fuera de la aplicacion —una prueba unitaria, un script— no hay
            // `Context`, y eso no es un error: es que no hay nada que copiar.
            return [];
        }
    }

    private function filled(mixed $value): bool
    {
        return is_scalar($value) && (string) $value !== '';
    }
}
