<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Engancha la peticion con la traza que **abrio el cliente** (doc 02 §8.1).
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
 * Este middleware extrae el contexto del W3C Trace Context de las cabeceras y lo
 * **activa** durante la peticion. A partir de ahi, cualquier span que se abra
 * —`attendance.register_scan`, `attendance.sync_scan_batch`— cuelga del que envio
 * el cliente sin que su codigo tenga que saber nada de cabeceras.
 *
 * ## Por que no hace nada mas
 *
 * No abre ningun span propio. Un span por peticion HTTP es trabajo del
 * instrumentador automatico de OpenTelemetry que llega con la tarea 3.1; lo que no
 * puede esperar a la 3.1 es la **continuidad** de la traza, porque sin ella los
 * spans que esta tarea si crea nacen huerfanos y no hay forma de arreglarlos a
 * posteriori.
 *
 * ## Nunca puede tumbar una peticion
 *
 * Una cabecera `traceparent` malformada —o un SDK mal configurado— no puede
 * convertir un fichaje correcto en un `500` (regla dura 19). Todo va envuelto y,
 * ante cualquier problema, la peticion sigue con su propia traza. Perder la
 * correlacion es infinitamente mas barato que perder una jornada.
 *
 * **Sin SDK configurado esto no cuesta nada**: `Context` funciona igual y los
 * spans son inertes, que es la situacion de la mayoria de las instalaciones.
 */
final class PropagateTraceContext
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $scope = $this->activateIncomingContext($request);

        try {
            return $next($request);
        } finally {
            $this->detach($scope);
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

    private function detach(?ScopeInterface $scope): void
    {
        if (! $scope instanceof ScopeInterface) {
            return;
        }

        try {
            $scope->detach();
        } catch (Throwable) {
            // Si el contexto ya se solto por otra via, soltarlo otra vez no puede
            // ser la causa de un error de la peticion. `Context` es un estado de
            // proceso y esta rama existe para que nunca se convierta en uno.
        }
    }
}
