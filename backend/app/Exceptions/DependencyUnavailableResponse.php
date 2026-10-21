<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Modules\Shared\Application\Support\SpanScope;
use App\Support\Database\DatabaseCircuitBreaker;
use App\Support\Database\DatabaseUnavailable;
use App\Support\Redis\RedisCircuitBreaker;
use App\Support\Resilience\DependencyFailure;
use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * El `503` de una dependencia caida, con su rastro (ADR-059 §1 y §2).
 *
 * - **La respuesta** ({@see self::for()}): `503 service-unavailable` con
 *   `Retry-After` igual al plazo del cortacircuitos de esa dependencia, y un
 *   cuerpo fijo que no la nombra. Nunca `500`: un `500` solo puede ser un
 *   defecto del producto.
 * - **El rastro** ({@see self::leaveTrail()}): una linea `warning`
 *   `http.dependency_unavailable` por peticion, con la dependencia, la ruta por
 *   su nombre, `retry_after` y la clase de la excepcion —la correlacion
 *   (`trace_id`, `device_id`, `employee_uuid`) la pone el procesador del log;
 *   **ni el mensaje, que lleva host y puerto, ni texto libre** (regla dura 21,
 *   ADR-048)—, y el span de la peticion con `status=error` y un evento
 *   `exception` con solo la clase ({@see SpanScope::markCurrentFailed()}).
 * - **`error_events`: nada.** `bootstrap/app.php` no informa estas excepciones,
 *   salvo la primera `RedisException`, la que abre el circuito.
 *
 * ## Clasificar no escribe
 *
 * `for()` solo compone la respuesta. El rastro lo deja **el `render` final** de
 * `bootstrap/app.php`, una vez. Quien necesita saber el estado sin responder
 * —`ServerErrorReporter`, que pregunta al manejador si algo seria un `4xx`— no
 * llega a este `render`: trata una dependencia caida como `503` antes de
 * renderizar. Asi un fallo de dependencia capturado a mitad de un lote (que no
 * es la respuesta de la peticion) no deja ni la linea ni el span en error.
 */
final class DependencyUnavailableResponse
{
    private function __construct() {}

    /**
     * El `503`, o `null` si la excepcion no es de una dependencia. No escribe.
     */
    public static function for(Throwable $failure, Request $request): ?JsonResponse
    {
        $dependency = DependencyFailure::dependencyOf($failure);

        if ($dependency === null) {
            return null;
        }

        return ProblemDetails::dependencyUnavailable(self::retryAfter($dependency, $failure), '/'.ltrim($request->path(), '/'));
    }

    /**
     * La linea del log y la marca del span de la peticion que responde ese `503`.
     * Solo la llama el `render` final.
     */
    public static function leaveTrail(Throwable $failure, Request $request): void
    {
        $dependency = DependencyFailure::dependencyOf($failure);

        if ($dependency === null) {
            return;
        }

        SpanScope::markCurrentFailed($failure);

        try {
            $route = $request->route();

            Container::getInstance()->make(LoggerInterface::class)->warning('http.dependency_unavailable', [
                'dependency' => $dependency,
                'route' => $route instanceof Route && $route->getName() !== null ? $route->getName() : 'unmatched',
                'method' => $request->getMethod(),
                'retry_after' => self::retryAfter($dependency, $failure),
                'failure' => $failure::class,
            ]);
        } catch (Throwable) {
            // Sin log, la respuesta sigue siendo el 503: el rastro no puede
            // convertirlo en un 500.
        }
    }

    private static function retryAfter(string $dependency, Throwable $failure): int
    {
        $unavailable = DatabaseUnavailable::foundIn($failure);

        if ($unavailable instanceof DatabaseUnavailable) {
            return max(1, $unavailable->retryAfterSeconds);
        }

        try {
            $breaker = $dependency === DependencyFailure::REDIS
                ? Container::getInstance()->make(RedisCircuitBreaker::class)
                : Container::getInstance()->make(DatabaseCircuitBreaker::class);

            return max(1, $breaker->retryAfterSeconds());
        } catch (Throwable) {
            return 10;
        }
    }
}
