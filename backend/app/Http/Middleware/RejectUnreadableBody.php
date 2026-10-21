<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ProblemDetails;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un cuerpo que el servidor no puede leer se rechaza; nunca se responde como si
 * se hubiera guardado (D6, V21-3-BE-3, F4a-4).
 *
 * Hasta la 2.3.0, un `PATCH /site` con JSON mal formado respondia `200` sin
 * cambiar nada: Laravel trata un JSON que no decodifica como un cuerpo vacio, y
 * con todos los campos opcionales la validacion pasaba. El cliente creia haber
 * guardado. Y un `Content-Type: text/plain` acababa en `422` por campos
 * ausentes, nunca en `415`.
 *
 * - **`Content-Type` JSON** (`application/json` o `+json`) con un cuerpo que no
 *   es JSON valido → `400 bad-request`.
 * - **Cualquier otro `Content-Type`** con cuerpo → `415 unsupported-media-type`.
 *   Las excepciones son cerradas: `multipart/form-data` en las dos rutas de
 *   subida de ficheros, y el formulario de la autorizacion de canales del
 *   WebSocket, que es el formato fijo del cliente de Pusher.
 * - **Sin cuerpo** no se mira nada: un `POST` de accion sin datos es legitimo.
 *
 * Va detras de la negociacion de idioma, para que el `400` y el `415` salgan en
 * el idioma pedido. En las rutas con sesion, la autenticacion va antes (es un
 * middleware con prioridad); en las puertas de acceso, que no la piden, decir
 * que el cuerpo no se entiende no revela nada de ninguna credencial.
 */
final class RejectUnreadableBody
{
    /** Rutas, por su nombre, que reciben un fichero en `multipart/form-data`. */
    public const array MULTIPART_ROUTES = ['employees.import', 'absences.import'];

    /** La autorizacion de canales: el cliente de Pusher envia un formulario. */
    public const string FORM_PATH = 'api/v1/broadcasting/auth';

    private const array METHODS_WITH_BODY = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! \in_array($request->getMethod(), self::METHODS_WITH_BODY, true)) {
            return $next($request);
        }

        $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type', ''))[0]));

        if ($type === 'multipart/form-data') {
            return $this->acceptsMultipart($request) ? $next($request) : $this->unsupported();
        }

        $content = $request->getContent();

        if (trim($content) === '') {
            return $next($request);
        }

        if ($type === 'application/json' || str_ends_with($type, '+json')) {
            return self::isJson($content) ? $next($request) : ProblemDetails::httpError(JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($type === 'application/x-www-form-urlencoded' && $request->is(self::FORM_PATH)) {
            return $next($request);
        }

        return $this->unsupported();
    }

    private function acceptsMultipart(Request $request): bool
    {
        $route = $request->route();

        return $route instanceof Route && \in_array($route->getName(), self::MULTIPART_ROUTES, true);
    }

    private static function isJson(string $content): bool
    {
        try {
            json_decode($content, flags: JSON_THROW_ON_ERROR);

            return true;
        } catch (JsonException) {
            return false;
        }
    }

    private function unsupported(): JsonResponse
    {
        return ProblemDetails::httpError(JsonResponse::HTTP_UNSUPPORTED_MEDIA_TYPE);
    }
}
