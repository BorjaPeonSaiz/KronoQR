<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Respuestas de error en `application/problem+json` (RFC 9457).
 *
 * **Un solo sitio donde se construye un error.** El contrato promete que toda
 * respuesta de error tiene esta forma y que `type` es un URN estable —lo que el
 * cliente interpreta— mientras `title` y `detail` son texto para personas. Si
 * cada controlador diera forma a sus errores, esa promesa duraria hasta el
 * tercer endpoint.
 *
 * **Vive fuera de los modulos a proposito.** Dar forma a un error HTTP no es
 * asunto de ningun dominio, y ademas tiene que poder usarse desde el manejador
 * global de `bootstrap/app.php`, que no puede depender de un modulo (Deptrac lo
 * verifica). Los modulos registran sus propias traducciones de excepcion en su
 * `ServiceProvider` y llaman aqui para el formato.
 *
 * **Lo que NO se hace aqui: el rechazo de escaneo.** Ese tiene su propia forma,
 * generica y de tiempo constante (regla dura 17, tarea 1.7), y no puede
 * construirse con una funcion que acepta `detail` libre.
 */
final class ProblemDetails
{
    public const string TYPE_VALIDATION_FAILED = 'urn:kronoqr:problem:validation-failed';

    public const string TYPE_UNAUTHENTICATED = 'urn:kronoqr:problem:unauthenticated';

    public const string TYPE_INVALID_CREDENTIALS = 'urn:kronoqr:problem:invalid-credentials';

    public const string TYPE_FORBIDDEN = 'urn:kronoqr:problem:forbidden';

    public const string TYPE_NOT_FOUND = 'urn:kronoqr:problem:not-found';

    public const string TYPE_CONFLICT = 'urn:kronoqr:problem:conflict';

    public const string TYPE_TOO_MANY_REQUESTS = 'urn:kronoqr:problem:too-many-requests';

    public const string TYPE_SERVICE_UNAVAILABLE = 'urn:kronoqr:problem:service-unavailable';

    /**
     * La instancia todavia no acepta trafico: `GET /api/v1/ready`.
     *
     * **Tipo propio y no `TYPE_SERVICE_UNAVAILABLE`**, aunque los dos sean
     * `503`, porque a quien los recibe le cambia la accion siguiente: aquel dice
     * «falta configuracion en el servidor, avisa a quien administra» y este dice
     * «reintenta, no me mandes trafico todavia». El segundo lo lee un
     * orquestador, no una persona.
     */
    public const string TYPE_NOT_READY = 'urn:kronoqr:problem:not-ready';

    /**
     * Peticion mal formada.
     *
     * **No es lo mismo que `TYPE_VALIDATION_FAILED` y la diferencia importa en
     * un solo endpoint: `POST /api/v1/scan`.** Alli el `422` esta reservado al
     * rechazo generico de escaneo (RS-03, regla dura 17), asi que un campo que
     * falta o una cabecera `Idempotency-Key` que no coincide con `scan_id`
     * tienen que llegar como `400`. Si compartieran codigo, el quiosco no podria
     * distinguir «tu peticion esta mal, no la reintentes tal cual» de «esta
     * tarjeta no vale», que son dos comportamientos opuestos para la cola
     * offline (RF-KI-04).
     */
    public const string TYPE_INVALID_REQUEST = 'urn:kronoqr:problem:invalid-request';

    /**
     * @param  array<string, list<string>>  $errors  Detalle por campo. Solo en errores de validacion.
     * @param  array<string, string>  $headers
     */
    public static function response(
        string $type,
        string $title,
        int $status,
        string $detail,
        array $errors = [],
        array $headers = [],
    ): JsonResponse {
        $body = [
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ];

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return new JsonResponse(
            $body,
            $status,
            // El tipo de medio es parte del contrato: un cliente que espera
            // `problem+json` y recibe `json` no sabe que hacer con el cuerpo.
            ['Content-Type' => 'application/problem+json', ...$headers],
        );
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function validationFailed(array $errors): JsonResponse
    {
        return self::response(
            self::TYPE_VALIDATION_FAILED,
            'Peticion no valida',
            JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            'Revisa los campos indicados.',
            $errors,
        );
    }

    /**
     * La peticion no cumple el contrato: `400`.
     *
     * Lo usa el borde del quiosco, donde el `422` significa otra cosa. El
     * cuerpo es el mismo `ValidationProblem` y por tanto el cliente generado no
     * necesita dos formas, solo dos codigos.
     *
     * @param  array<string, list<string>>  $errors
     */
    public static function invalidRequest(array $errors): JsonResponse
    {
        return self::response(
            self::TYPE_INVALID_REQUEST,
            'Peticion invalida',
            JsonResponse::HTTP_BAD_REQUEST,
            'La peticion no cumple el contrato.',
            $errors,
        );
    }

    public static function conflict(string $detail): JsonResponse
    {
        return self::response(
            self::TYPE_CONFLICT,
            'Conflicto con el estado actual',
            JsonResponse::HTTP_CONFLICT,
            $detail,
        );
    }

    public static function notFound(): JsonResponse
    {
        return self::response(
            self::TYPE_NOT_FOUND,
            'Recurso no encontrado',
            JsonResponse::HTTP_NOT_FOUND,
            'No existe el recurso solicitado.',
        );
    }

    public static function forbidden(): JsonResponse
    {
        return self::response(
            self::TYPE_FORBIDDEN,
            'Acceso denegado',
            JsonResponse::HTTP_FORBIDDEN,
            'El token no tiene el ambito necesario para esta operacion.',
        );
    }

    public static function unauthenticated(): JsonResponse
    {
        return self::response(
            self::TYPE_UNAUTHENTICATED,
            'No autenticado',
            JsonResponse::HTTP_UNAUTHORIZED,
            'Se requiere un token valido.',
        );
    }

    /**
     * Credenciales no validas: **una sola respuesta para las tres causas**
     * —correo desconocido, contrasena incorrecta y cuenta desactivada—. Si el
     * texto variara, el panel seria un comprobador de cuentas de la empresa.
     */
    /**
     * `401` con el mismo `type` para las dos puertas de acceso del producto.
     *
     * **El `detail` cambia y el `type` no**, y ese reparto es el que importa: el
     * `type` es el URN estable que el cliente interpreta —y los dos accesos
     * quieren la misma reaccion, «vuelve a pedir credenciales»—, mientras que el
     * `detail` es texto para una persona y tiene que nombrar lo que esa persona
     * acaba de teclear. Decirle «el correo o la contraseña» a quien entro con su
     * codigo de empleado y su PIN es decirle que revise algo que no existe
     * (ADR-015, regla dura 12).
     *
     * Lo que **no** puede cambiar es que la respuesta sea la misma para todas
     * las causas de cada puerta: en el portal son cinco —codigo inexistente, PIN
     * incorrecto, PIN no emitido, empleado no en alta y bloqueo activo— y
     * ninguna se distingue desde fuera (RS-03, regla dura 17).
     */
    public static function invalidCredentials(?string $detail = null): JsonResponse
    {
        return self::response(
            self::TYPE_INVALID_CREDENTIALS,
            'Credenciales no validas',
            JsonResponse::HTTP_UNAUTHORIZED,
            $detail ?? 'El correo o la contrasena no son correctos.',
        );
    }

    /**
     * La instalacion no esta en condiciones de atender esta operacion, y no es
     * culpa de quien la pide: falta configuracion del servidor.
     *
     * `503` y no `500` porque la distincion le cambia la accion a quien la
     * recibe —reintentar mas tarde, avisar a quien administra— y porque un `500`
     * se lee como «error del producto» cuando aqui el producto funciona.
     *
     * **El detalle dice que falta, nunca su valor.** Ninguna clave, ninguna
     * parte de ella y ninguna ruta de fichero salen por aqui.
     */
    public static function serviceUnavailable(string $detail): JsonResponse
    {
        return self::response(
            self::TYPE_SERVICE_UNAVAILABLE,
            'Servicio no disponible',
            JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            $detail,
        );
    }

    /**
     * La instancia no esta lista para recibir trafico.
     *
     * **No acepta ningun detalle, y esa firma es el control**: el cuerpo es
     * identico con la base de datos caida, con Redis caido o con las dos. Si
     * este metodo admitiera un `detail` libre, la sonda publica acabaria
     * enumerando los servicios caidos de la instalacion el dia que alguien
     * quisiera depurar mas comodo. La causa se escribe en el log del servidor.
     */
    public static function notReady(): JsonResponse
    {
        return self::response(
            self::TYPE_NOT_READY,
            'Servicio no disponible',
            JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            'La instancia todavia no esta lista para recibir trafico.',
        );
    }

    public static function tooManyRequests(int $retryAfterSeconds): JsonResponse
    {
        return self::response(
            self::TYPE_TOO_MANY_REQUESTS,
            'Demasiadas peticiones',
            JsonResponse::HTTP_TOO_MANY_REQUESTS,
            'Reintenta pasados unos segundos.',
            headers: ['Retry-After' => (string) max(1, $retryAfterSeconds)],
        );
    }
}
