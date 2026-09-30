<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Psr\Log\LoggerInterface;
use RedisException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * **El limitador del fichaje falla ABIERTO si su almacen no responde** (CH1,
 * regla dura 19, RS-02).
 *
 * `throttle:scan`, `throttle:scan-batch` y `throttle:scan-pin` cuentan en la
 * cache, que en produccion es Redis. Con Redis caido, `ThrottleRequests` lanzaba
 * la `RedisException` y el quiosco recibia un `500` por cada fichaje: el
 * empleado no podia fichar por ninguna via del servidor mientras durase la
 * averia, y la cola offline del hotel entero crecia sin que nadie lo notara
 * hasta `ColaOfflineSinVaciar` (tanda 3 de la verificacion de la 2.1.0).
 *
 * ## Por que abrir, y por que solo aqui
 *
 * El limitador por dispositivo es un control **secundario** del fichaje: el
 * primario es Nginx, que limita por origen sin tocar Redis (§7.1), y en el PIN
 * lo es ademas el bloqueo por empleado. Dejar de fichar porque el contador no
 * responde es cambiar un riesgo acotado —unos minutos sin el techo por tablet—
 * por uno seguro: jornadas sin registrar.
 *
 * **Nunca en `auth`, `2fa`, `portal`, `setup` ni en gestion.** Alli el limitador
 * es la defensa contra la fuerza bruta de una credencial, y fallar abierto la
 * desactivaria justo durante una averia, que es cuando nadie mira. Esas rutas
 * siguen con el `throttle` del framework y fallan CERRADAS. Lo vigila
 * `ThrottleFailOpenScopeTest`, que enumera el router.
 *
 * ## Que se abre exactamente
 *
 * Solo el fallo del **almacen** del limitador, y solo el que ocurre en la fase
 * del propio limitador:
 *
 * - Un `429` legitimo sigue siendo un `429`: la excepcion que se captura es la
 *   del cliente de Redis, no `ThrottleRequestsException`.
 * - Lo que lance el controlador **no se toca**. Si Redis falla dentro del caso de
 *   uso, esa excepcion sigue su camino; reintentar la peticion aqui duplicaria el
 *   trabajo. Para distinguir las dos fases, la continuacion marca que ya se paso
 *   por ella.
 * - Si el fallo llega **despues** de responder —`ThrottleRequests` vuelve a la
 *   cache para calcular las cabeceras `X-RateLimit-*`—, se devuelve la respuesta
 *   ya construida, sin esas cabeceras. El fichaje ya esta confirmado: convertirlo
 *   ahora en un `500` haria que el quiosco lo reenviara por nada.
 *
 * ## El rastro
 *
 * Un `warning` estructurado `attendance.rate_limiter_fail_open` con la zona, la
 * fase y la clase de la excepcion —nunca su mensaje, que lleva host y puerto—.
 * Sin metrica propia, y no por olvido: las metricas del producto se guardan en el
 * mismo Redis que acaba de fallar. Lo que alerta de la averia es la sonda de
 * Redis de `/ready` y del exportador; esta linea dice que efecto tuvo.
 *
 * Datos personales no hay ninguno (regla dura 21): ni empleado, ni codigo, ni IP.
 */
final readonly class ThrottleScanFailOpen
{
    /** Las tres zonas que pueden abrirse. Cualquier otra se rechaza al arrancar la ruta. */
    public const array ZONES = ['scan', 'scan-batch', 'scan-pin'];

    public function __construct(
        private ThrottleRequests $throttle,
        private LoggerInterface $logger,
    ) {}

    /**
     * La declaracion de la ruta: `ThrottleScanFailOpen::zone('scan')`.
     */
    public static function zone(string $zone): string
    {
        if (! \in_array($zone, self::ZONES, true)) {
            throw new \InvalidArgumentException('Only the clocking zones may fail open, not '.$zone.'.');
        }

        return self::class.':'.$zone;
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $zone): Response
    {
        if (! \in_array($zone, self::ZONES, true)) {
            // Defensa en profundidad: una ruta nueva que nombre la clase a mano
            // con otra zona no hereda el fallo abierto.
            throw new \InvalidArgumentException('Only the clocking zones may fail open, not '.$zone.'.');
        }

        $reachedNext = false;
        $response = null;

        try {
            return $this->throttle->handle(
                $request,
                static function (Request $request) use ($next, &$reachedNext, &$response): Response {
                    $reachedNext = true;
                    $response = $next($request);

                    return $response;
                },
                $zone,
            );
        } catch (Throwable $failure) {
            if (! self::isStoreUnavailable($failure)) {
                throw $failure;
            }

            if (! $reachedNext) {
                $this->record($zone, 'before', $failure);

                return $next($request);
            }

            // El fallo vino de dentro del controlador: no es asunto de este
            // middleware y no se reintenta nada.
            if (! $response instanceof Response) {
                throw $failure;
            }

            $this->record($zone, 'after', $failure);

            return $response;
        }
    }

    /**
     * El almacen del limitador no responde.
     *
     * `RedisException` es la del cliente `phpredis`, que es el del producto
     * (`REDIS_CLIENT`). Se nombra tambien la de Predis por nombre, sin importarla:
     * una instalacion que cambiara de cliente no deberia perder el fallo abierto,
     * y Predis no es una dependencia del producto.
     */
    private static function isStoreUnavailable(Throwable $failure): bool
    {
        return $failure instanceof RedisException
            || is_a($failure, 'Predis\Connection\ConnectionException');
    }

    private function record(string $zone, string $phase, Throwable $failure): void
    {
        $this->logger->warning('attendance.rate_limiter_fail_open', [
            'zone' => $zone,
            'phase' => $phase,
            'failure' => $failure::class,
        ]);
    }
}
