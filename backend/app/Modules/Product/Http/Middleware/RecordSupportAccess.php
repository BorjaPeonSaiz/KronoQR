<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Middleware;

use App\Modules\Product\Application\UseCase\RecordSupportAccessUseHandler;
use App\Modules\Product\Infrastructure\Persistence\SupportGrant;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Deja constancia de que un acceso de soporte se ha usado (**RF-PD-11**,
 * ADR-020, regla dura 6).
 *
 * ## Por que es un middleware global y no una llamada en cada controlador
 *
 * Porque **el uso es la peticion, no el endpoint**. Un token de soporte con
 * alcance `read_only` alcanza una docena larga de rutas de tres modulos
 * distintos, y la promesa de RF-PD-11 es que *cada acceso efectivo* queda
 * registrado. Repartir la llamada por cada controlador significaria que la
 * promesa se cumple hasta que alguien añada un endpoint y se olvide, y ese
 * olvido no rompe ninguna prueba de ese endpoint: simplemente deja de haber
 * rastro.
 *
 * Va en el grupo `api` entero. **No filtra por ruta y no debe**: si mañana
 * aparece una ruta nueva que un token de soporte alcanza, queda cubierta sin que
 * nadie se acuerde de nada.
 *
 * ## Corre DESPUES de la respuesta, y por eso funciona
 *
 * El grupo `api` se ejecuta antes que el `auth:sanctum` de cada ruta, asi que en
 * el camino de ida todavia no hay actor resuelto. Lo que hace este middleware es
 * dejar pasar, y anotar **a la vuelta**, cuando la peticion ya esta autenticada y
 * respondida. Dos consecuencias deliberadas:
 *
 * - **Una peticion rechazada por la policy tambien cuenta como uso.** Y tiene que
 *   contar: que alguien con acceso de soporte intente entrar donde no le
 *   corresponde es exactamente el hecho que el cliente quiere ver en su trail.
 * - **Una peticion que no llega a autenticarse no cuenta.** No hay actor, no hay
 *   concesion, no hay nada que anotar.
 *
 * ## No puede tumbar la peticion que describe
 *
 * Todo el trabajo va envuelto. Es la excepcion razonada a la regla dura 6 —el
 * asiento sincrono— y el razonamiento esta en
 * {@see RecordSupportAccessUseHandler}: la prueba de la potestad es la concesion,
 * que si es transaccional; esto es su rastro de ejercicio, y perderlo por un
 * fallo de base de datos es preferible a devolverle un `500` a quien esta
 * intentando arreglar una incidencia. El fallo queda en el log tecnico.
 *
 * ## Nunca escribe la URL concreta
 *
 * Al asiento va el **patron** de la ruta (`api/v1/employees/{uuid}`) y no la URL
 * con sus identificadores: un UUID de empleado en `audit_log.payload` seria un
 * dato personal escrito en un sitio que se exporta (regla dura 21).
 *
 * ## El caso de uso se resuelve TARDE, no por constructor
 *
 * Este middleware corre en todo el grupo `api`, **sondas incluidas**, y el caso
 * de uso abre la conexion a la base de datos al construirse. Inyectarlo aqui
 * haria que `/health` dependiera de PostgreSQL, que es justo lo que la sonda de
 * vida no puede hacer (§10.5): con la base de datos caida, el orquestador
 * reiniciaria un contenedor que no tiene nada malo. Solo se construye cuando el
 * actor es una concesion de soporte, que es el unico caso en que hace falta.
 */
final readonly class RecordSupportAccess
{
    public function __construct(
        private Container $container,
        private LoggerInterface $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // `@var` y no confianza en la firma: para el analizador estatico
        // `user()` devuelve la cuenta de gestion configurada en `auth.php`, y en
        // este producto el guard de la API tiene CUATRO `tokenable` distintos
        // —cuenta, quiosco, sesion de portal y concesion de soporte—. Sin esta
        // anotacion, PHPStan da por imposible la comprobacion de abajo y borra
        // el unico camino que este middleware tiene.
        /** @var Model|null $actor */
        $actor = $request->user();

        if (! $actor instanceof SupportGrant) {
            return $response;
        }

        try {
            $this->container->make(RecordSupportAccessUseHandler::class)->handle(
                grantId: $actor->id,
                grantUuid: $actor->uuid,
                scope: $actor->scope,
                method: $request->getMethod(),
                route: self::patternOf($request),
            );
        } catch (Throwable $exception) {
            // El `grant_uuid` y nada mas identificativo que eso: este log lo
            // lee el cliente y puede acabar en el paquete de diagnostico.
            $this->logger->error('product.support_access_not_recorded', [
                'grant_uuid' => $actor->uuid,
                'reason' => $exception::class,
            ]);
        }

        return $response;
    }

    /**
     * El patron registrado de la ruta, o el metodo a secas si no hay ruta
     * —un `404`, por ejemplo—, que tambien es informacion util: dice que el
     * acceso se uso para buscar algo que no existe.
     */
    private static function patternOf(Request $request): string
    {
        $route = $request->route();

        return $route instanceof Route ? $route->uri() : 'unknown';
    }
}
