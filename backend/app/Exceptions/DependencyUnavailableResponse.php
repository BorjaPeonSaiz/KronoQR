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
use WeakMap;

/**
 * El `503` de una dependencia caida, con su rastro (ADR-059 §1 y §2).
 *
 * - **La respuesta:** `503 service-unavailable` con `Retry-After` igual al plazo
 *   del cortacircuitos de esa dependencia, y un cuerpo fijo que no la nombra.
 *   Nunca `500`: un `500` solo puede ser un defecto del producto.
 * - **El log:** una linea `warning` `http.dependency_unavailable` por peticion,
 *   con la dependencia, la ruta por su nombre, `retry_after` y la clase de la
 *   excepcion. La correlacion (`trace_id`, `device_id`, `employee_uuid`) la
 *   pone el procesador del log. **Ni el mensaje, que lleva host y puerto, ni
 *   texto libre** (regla dura 21, ADR-048).
 * - **La traza:** el span de la peticion con `status=error` y un evento
 *   `exception` con solo la clase ({@see SpanScope::markCurrentFailed()}).
 * - **`error_events`: nada.** `bootstrap/app.php` no informa estas excepciones,
 *   salvo la primera `RedisException`, la que abre el circuito.
 *
 * Una sola linea por excepcion aunque se renderice dos veces: el historico de
 * errores renderiza la primera `RedisException` para saber que estado tendria.
 */
final class DependencyUnavailableResponse
{
    /** @var WeakMap<Throwable, true>|null */
    private static ?WeakMap $recorded = null;

    private function __construct() {}

    public static function for(Throwable $failure, Request $request): ?JsonResponse
    {
        $dependency = DependencyFailure::dependencyOf($failure);

        if ($dependency === null) {
            return null;
        }

        $retryAfter = self::retryAfter($dependency, $failure);

        self::record($failure, $dependency, $retryAfter, $request);

        return ProblemDetails::dependencyUnavailable($retryAfter, '/'.ltrim($request->path(), '/'));
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

    private static function record(Throwable $failure, string $dependency, int $retryAfter, Request $request): void
    {
        self::$recorded ??= new WeakMap;

        if (isset(self::$recorded[$failure])) {
            return;
        }

        self::$recorded[$failure] = true;

        SpanScope::markCurrentFailed($failure);

        try {
            $route = $request->route();

            Container::getInstance()->make(LoggerInterface::class)->warning('http.dependency_unavailable', [
                'dependency' => $dependency,
                'route' => $route instanceof Route && $route->getName() !== null ? $route->getName() : 'unmatched',
                'method' => $request->getMethod(),
                'retry_after' => $retryAfter,
                'failure' => $failure::class,
            ]);
        } catch (Throwable) {
            // Sin log, la respuesta sigue siendo el 503: el rastro no puede
            // convertirlo en un 500.
        }
    }
}
