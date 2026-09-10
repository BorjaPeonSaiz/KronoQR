<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Shared\Application\Support\SpanScope;
use App\Modules\Shared\Application\Support\TraceparentHeader;
use App\Support\Observability\Tracing\TraceContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Context as LogContext;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\ScopeInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Engancha la peticion con la traza que **abrio el cliente**, abre el span de
 * servidor y deja la correlacion a mano del log (doc 02 §8.1, tarea 3.1).
 *
 * ## Que problema resuelve
 *
 * El §8.1 promete poder seguir un fichaje *«desde el `fetch` del navegador del
 * quiosco hasta la consulta SQL»*. Esa promesa se sostiene sobre una cabecera,
 * `traceparent`, que viaja en la peticion y contiene el identificador de traza que
 * el cliente ya habia empezado. Si nadie la lee, cada peticion abre una traza
 * nueva: los spans del servidor existen, se ven bonitos y **no se pueden unir con
 * lo que hizo la tablet**, que es justo lo que hace falta cuando alguien pregunta
 * por que su fichaje de las 06:00 tardo cuatro segundos.
 *
 * Este middleware hace tres cosas, en este orden:
 *
 * 1. **Extrae y activa** el contexto del W3C Trace Context de las cabeceras.
 * 2. **Abre el span `SERVER`** de la peticion y lo activa, de modo que todo lo que
 *    se abra despues —`attendance.register_scan`, `attendance.sync_scan_batch`,
 *    los spans de cada consulta SQL— cuelga de el sin que su codigo tenga que
 *    saber nada de cabeceras.
 * 3. **Deja `trace_id` y `traceparent` en el `Context` de Laravel**, que es lo que
 *    el processor de correlacion del log lee para poner `trace_id` en TODA linea
 *    —no solo en las que escriben las clases `*Telemetry`— y lo que viaja con un
 *    trabajo encolado para que el worker siga la misma traza.
 *
 * ## El nombre del span es el de la RUTA, nunca la URI
 *
 * Misma decision que en {@see RecordHttpMetrics} y por los mismos dos motivos.
 * Cardinalidad: con la URI, `/api/v1/employees/{uuid}` produce un nombre de span
 * por empleado. Y datos personales: una lista de nombres de span es una lista de
 * identificadores escrita en un sistema sin control de acceso por dato (regla dura
 * 21). Una peticion que no casa con ninguna ruta con nombre se agrupa como
 * `unmatched`.
 *
 * ## Nunca puede tumbar una peticion
 *
 * Una cabecera `traceparent` malformada —o un SDK mal configurado— no puede
 * convertir un fichaje correcto en un `500` (regla dura 19). Todo va envuelto y,
 * ante cualquier problema, la peticion sigue con su propia traza. Perder la
 * correlacion es infinitamente mas barato que perder una jornada.
 *
 * **Sin SDK configurado esto no cuesta nada**: `Context` funciona igual, los spans
 * son inertes y el `trace_id` que se publica es el que trajo el cliente en su
 * cabecera —que es la situacion de la mayoria de las instalaciones y la razon de
 * que la correlacion del log no dependa de exportar trazas—.
 */
final class PropagateTraceContext
{
    /** @var non-empty-string */
    private const string TRACER = 'kronoqr.http';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $scope = $this->activateIncomingContext($request);
        $span = $this->startServerSpan($request);

        $this->publishCorrelation($request);

        try {
            $response = $next($request);

            $span->end(['http.response.status_code' => $response->getStatusCode()]);

            return $response;
        } catch (Throwable $failure) {
            // El span se cierra tambien cuando la peticion revienta: una traza que
            // se corta justo donde ocurrio el fallo es la que menos sirve.
            $span->end(['http.response.status_code' => 500]);

            throw $failure;
        } finally {
            TraceContext::detach($scope);
        }
    }

    private function activateIncomingContext(Request $request): ?ScopeInterface
    {
        try {
            /** @var array<string, list<string|null>> $headers */
            $headers = $request->headers->all();

            return TraceContextPropagator::getInstance()->extract($headers)->activate();
        } catch (Throwable) {
            // Cabecera malformada o SDK a medio configurar: la peticion sigue con
            // su propia traza. Ver el docblock de la clase.
            return null;
        }
    }

    private function startServerSpan(Request $request): SpanScope
    {
        $route = $this->routeNameOf($request);

        return SpanScope::startActive(
            self::TRACER,
            $request->getMethod().' '.$route,
            SpanKind::KIND_SERVER,
            [
                'http.request.method' => $request->getMethod(),
                'http.route' => $route,
            ],
        );
    }

    /**
     * Deja la correlacion donde la encuentra todo el mundo.
     *
     * `trace_id` sale del span recien abierto si hay SDK, y si no de la cabecera
     * que envio el cliente: las dos son el mismo identificador cuando las dos
     * existen, porque el span cuelga del contexto extraido.
     *
     * ## LA CABECERA ENTRANTE SE VALIDA ANTES DE PUBLICARLA
     *
     * Y no es una comprobacion de higiene. `traceparent` la escribe quien haga
     * la peticion, con lo que quiera y de la longitud que quiera. Lo que se
     * publica en el `Context` de Laravel lo copia `ContextLogProcessor` a
     * **todas** las lineas de log de la peticion, y de ahi va a Loki, donde se
     * queda el plazo de retencion entero (90 dias, RL-11). Publicar la cabecera
     * en crudo convierte cualquier peticion anonima en un canal de escritura de
     * ocho kilobytes por linea de log en el servidor del cliente.
     *
     * Asi que: si no casa con {@see TraceparentHeader::PATTERN} —la forma exacta
     * del W3C, anclada por los dos extremos— **no se publica**, y la clave se
     * borra. Lo que si se publica entonces es el `traceparent` del span propio,
     * cuando hay SDK: una peticion con la cabecera rota abre su propia traza y
     * la correlacion sigue existiendo, solo que empieza aqui.
     *
     * **Se escriben o se borran, nunca se dejan a medias.** El `Context` de
     * Laravel es un estado de proceso, y en un worker de vida larga una clave
     * heredada de la peticion anterior es peor que ninguna: fecharia una linea con
     * la traza equivocada.
     */
    private function publishCorrelation(Request $request): void
    {
        try {
            $incoming = $request->headers->get(TraceparentHeader::NAME);
            $traceparent = TraceContext::currentTraceparent()
                ?? (TraceparentHeader::valid($incoming) ? $incoming : null);

            $traceId = TraceContext::currentTraceId() ?? TraceparentHeader::traceIdOf($incoming);

            $this->remember(TraceContext::TRACE_ID_KEY, $traceId);
            $this->remember(TraceContext::TRACEPARENT_KEY, $traceparent);
        } catch (Throwable) {
            // Ver el docblock de la clase.
        }
    }

    private function remember(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            LogContext::forget($key);

            return;
        }

        LogContext::add($key, $value);
    }

    /**
     * @return non-empty-string
     */
    private function routeNameOf(Request $request): string
    {
        $route = $request->route();

        if ($route instanceof Route) {
            $name = $route->getName();

            if ($name !== null && $name !== '') {
                return $name;
            }
        }

        return RecordHttpMetrics::UNMATCHED_ROUTE;
    }
}
