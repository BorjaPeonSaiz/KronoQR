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
     * Tipo propio y no `TYPE_SERVICE_UNAVAILABLE`: el cliente que lo recibe no
     * tiene que avisar a nadie, tiene que ESPERAR y reintentar pasado
     * `Retry-After`. Es lo que hace el quiosco con su cola (regla dura 19) y lo
     * que el panel muestra como «actualizacion en curso» (RF-PD-10).
     */
    public const string TYPE_MAINTENANCE = 'urn:kronoqr:problem:maintenance';

    /**
     * Una funcionalidad **accesoria** no esta disponible con la licencia
     * activada (ADR-019, ADR-023).
     *
     * Tipo propio y no `TYPE_FORBIDDEN`, aunque los dos denieguen, porque a
     * quien lo recibe le cambia por completo la accion siguiente: aquel dice
     * «pide permiso a quien administra» y este dice «tu empresa tiene que
     * renovar». **Nunca acompaña al registro legal**, que no es licenciable.
     */
    public const string TYPE_FEATURE_NOT_LICENSED = 'urn:kronoqr:problem:feature-not-licensed';

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
     * Ya hay una exportacion integra `pending` o `running` (**RF-PD-14**, tarea
     * 5.10).
     *
     * **Tipo propio y no `TYPE_CONFLICT`**, aunque los dos sean `409`, porque a
     * quien lo recibe le cambia por completo lo que tiene que hacer: el generico
     * dice «tu peticion choca con el estado actual, mira que ha pasado» y este
     * dice «ya se esta haciendo lo que pides, espera y mira la que hay». El panel
     * enseña esa exportacion en lugar de pedir otra, y para eso la fila viaja en
     * el cuerpo.
     */
    public const string TYPE_DATA_EXPORT_IN_PROGRESS = 'urn:kronoqr:problem:data-export-in-progress';

    /**
     * Se ha pedido descargar una exportacion integra que todavia no ha terminado
     * (**RF-PD-14**).
     *
     * **`409` y no `404`**: `404` diria «esto no existe, deja de intentarlo» y el
     * panel dejaria de sondear justo cuando la generacion esta a medias. Una que
     * fallo, o cuyo fichero ya se purgo, si es `404`, porque ahi de verdad no hay
     * nada que esperar.
     */
    public const string TYPE_DATA_EXPORT_NOT_READY = 'urn:kronoqr:problem:data-export-not-ready';

    /**
     * El `{uuid}` de la correccion existio y **ya no es la version vigente**:
     * otra persona lo corrigio o lo anulo antes (ADR-035, tarea 5.11b tras la
     * revision de codigo).
     *
     * **Tipo propio y no `TYPE_CONFLICT`**, aunque los tres conflictos de la
     * correccion sean `409`, porque el panel tiene que reaccionar distinto a
     * cada uno y analizar el `detail` para adivinarlo es exactamente lo que el
     * `type` existe para evitar: aqui hay que **recargar la jornada** y repetir
     * sobre el identificador nuevo, mientras que en los otros dos hay que
     * enseñar el tramo que estorba.
     */
    public const string TYPE_SHIFT_ENTRY_SUPERSEDED = 'urn:kronoqr:problem:shift-entry-superseded';

    /**
     * La operacion dejaria a esa persona con **dos turnos abiertos** (RN-01).
     *
     * Solo sale por las rutas de correccion. En el camino de fichaje la misma
     * excepcion no llega nunca al cliente: alli el rechazo es generico y de
     * tiempo constante (RS-03, regla dura 17).
     */
    public const string TYPE_SHIFT_ALREADY_OPEN = 'urn:kronoqr:problem:shift-already-open';

    /**
     * Las horas pisarian a **otro tramo vigente** de la misma persona (RN-02).
     *
     * Mismo acotamiento que {@see self::TYPE_SHIFT_ALREADY_OPEN}: rutas de
     * correccion y nada mas.
     */
    public const string TYPE_OVERLAPPING_SHIFT_ENTRY = 'urn:kronoqr:problem:overlapping-shift-entry';

    /**
     * La entrada que abre la jornada se moveria al otro lado de la medianoche
     * local y esas horas acabarian en **otra jornada** (RN-05, ADR-035).
     *
     * **Sigue siendo `422` y con el error colgado del campo**, no `409`: no hay
     * nada que releer ni ninguna carrera que haya perdido nadie. Lo que se pide
     * es imposible en un solo acto, y el mensaje del campo dice cual es el
     * camino —anular en origen, dar de alta en destino—.
     *
     * Tipo propio y no `TYPE_VALIDATION_FAILED` porque el panel puede ofrecer
     * ese camino en dos botones en lugar de pintar un texto largo junto a un
     * campo de hora.
     */
    public const string TYPE_CORRECTION_WOULD_CHANGE_WORK_DATE = 'urn:kronoqr:problem:correction-would-change-work-date';

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
     * El texto de un error de DOMINIO, en el idioma negociado por la peticion.
     *
     * **Por que hace falta.** Una excepcion de dominio no puede llevar texto de
     * usuario dentro: `Domain/` no sabe en que idioma se va a leer, y meter
     * castellano ahi le responde en castellano a un panel puesto en ingles.
     * Asi que el dominio expone una CLAVE de traduccion y sus parametros, y este
     * metodo la resuelve aqui, en el borde, donde `NegotiateLocale` ya ha
     * decidido el idioma.
     *
     * **Y si falta la traduccion, se devuelve el mensaje tecnico** en vez de la
     * clave: `settings.errors.out_of_range` no le dice nada a nadie, y el mensaje
     * en ingles al menos explica el problema. Que falte es un defecto del
     * producto, no del cliente, y lo caza la prueba de idioma del endpoint.
     *
     * @param  array<string, string|int>  $parameters
     */
    public static function translated(string $key, array $parameters, string $fallback): string
    {
        $message = trans($key, $parameters);

        return is_string($message) && $message !== $key ? $message : $fallback;
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

    /**
     * `409` de la exportacion integra, **con la que ya esta en curso dentro**
     * (RF-PD-14, tarea 5.10).
     *
     * El cuerpo lleva `export` porque es lo unico util que el panel puede hacer
     * con este error: enseñar la exportacion que hay, con su estado y su hora,
     * en lugar de un mensaje que invita a volver a pulsar el boton.
     *
     * **Recibe un array y no el modelo de dominio**, y no es pereza: esta clase
     * vive fuera de los modulos a proposito —tiene que poder usarse desde
     * `bootstrap/app.php`— y Deptrac le prohibe nombrar un tipo de
     * `App\Modules\*`. Quien la llama es el controlador, que si puede serializar.
     *
     * @param  array<string, mixed>  $export  La fila ya serializada con la forma del contrato.
     */
    public static function dataExportInProgress(string $detail, array $export): JsonResponse
    {
        $response = self::response(
            self::TYPE_DATA_EXPORT_IN_PROGRESS,
            'Ya hay una exportacion en curso',
            JsonResponse::HTTP_CONFLICT,
            $detail,
        );

        /** @var array<string, mixed> $body */
        $body = $response->getData(true);

        return $response->setData([...$body, 'export' => $export]);
    }

    /** `409` al descargar una exportacion integra que aun no ha terminado (RF-PD-14). */
    public static function dataExportNotReady(): JsonResponse
    {
        return self::response(
            self::TYPE_DATA_EXPORT_NOT_READY,
            'La exportacion todavia no ha terminado',
            JsonResponse::HTTP_CONFLICT,
            'Sigue en curso. Vuelve a consultarla dentro de unos segundos.',
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

    /**
     * Los tres `409` de la correccion de tramos, cada uno con su `type`
     * (tarea 5.11b, segunda vuelta de `revisor-codigo`).
     *
     * **Por que tres metodos y no un `conflict($detail, $type)`.** Porque el
     * segundo parametro lo acabaria rellenando quien pasara por aqui con prisa,
     * y el `type` es lo unico de esta respuesta que un cliente puede interpretar
     * sin leer castellano. Con un metodo por causa, el conjunto de tipos que
     * puede salir de la correccion esta escrito y es enumerable; con un
     * parametro libre, es lo que haya en el sitio de la llamada.
     *
     * El `title` es el mismo en los tres —son conflictos con el estado— y el
     * `detail` lo pone quien llama, que es quien conoce la operacion.
     */
    public static function shiftEntrySuperseded(string $detail): JsonResponse
    {
        return self::response(
            self::TYPE_SHIFT_ENTRY_SUPERSEDED,
            'Conflicto con el estado actual',
            JsonResponse::HTTP_CONFLICT,
            $detail,
        );
    }

    /** `409` de RN-01 en las rutas de correccion. {@see self::TYPE_SHIFT_ALREADY_OPEN} */
    public static function shiftAlreadyOpen(string $detail): JsonResponse
    {
        return self::response(
            self::TYPE_SHIFT_ALREADY_OPEN,
            'Conflicto con el estado actual',
            JsonResponse::HTTP_CONFLICT,
            $detail,
        );
    }

    /** `409` de RN-02 en las rutas de correccion. {@see self::TYPE_OVERLAPPING_SHIFT_ENTRY} */
    public static function overlappingShiftEntry(string $detail): JsonResponse
    {
        return self::response(
            self::TYPE_OVERLAPPING_SHIFT_ENTRY,
            'Conflicto con el estado actual',
            JsonResponse::HTTP_CONFLICT,
            $detail,
        );
    }

    /**
     * `422` de RN-05 en las rutas de correccion, con el error colgado del campo.
     *
     * **Mismo cuerpo que `validationFailed()` salvo el `type`**, y por eso mismo
     * es un metodo aparte y no un parametro de aquel: el resto del producto no
     * tiene ningun motivo para elegir el `type` de un error de validacion, y
     * poder hacerlo invita a que aparezca un tipo nuevo por endpoint.
     *
     * @param  array<string, list<string>>  $errors
     */
    public static function correctionWouldChangeWorkDate(array $errors): JsonResponse
    {
        return self::response(
            self::TYPE_CORRECTION_WOULD_CHANGE_WORK_DATE,
            'Peticion no valida',
            JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            'Revisa los campos indicados.',
            $errors,
        );
    }

    /**
     * Una funcionalidad **accesoria** no esta disponible con esta licencia
     * (ADR-019, ADR-023, RF-PD-05, tarea 5.3).
     *
     * ## `402` y no `403`
     *
     * ADR-019 exige que cada funcionalidad accesoria *«responda con el aviso de
     * licencia y no con un error generico»*. Un `403` mezclaria «no tienes
     * permiso» con «tu empresa no ha renovado», que son dos problemas de dos
     * personas distintas —lo primero lo arregla quien administra los roles, lo
     * segundo quien firma el contrato— y en un log serian indistinguibles. `402
     * Payment Required` es el unico codigo cuyo significado es exactamente este.
     *
     * ## Lo que este codigo NUNCA puede acompañar
     *
     * El fichaje, la consulta de jornadas, el portal, la exportacion para la
     * Inspeccion, la auditoria, las correcciones, las copias ni las sondas. No
     * por disciplina: la excepcion que llega hasta aqui solo se puede construir
     * a partir de un `Feature`, y ese catalogo no tiene ningun caso del conjunto
     * legal (regla dura 15).
     *
     * `feature` y `restriction` viajan en el cuerpo para que el panel pueda
     * decidir que enseñar sin analizar el texto.
     */
    public static function featureNotLicensed(
        string $detail,
        string $feature,
        ?string $restriction,
        ?string $since,
    ): JsonResponse {
        $response = self::response(
            self::TYPE_FEATURE_NOT_LICENSED,
            'Funcionalidad no disponible con esta licencia',
            JsonResponse::HTTP_PAYMENT_REQUIRED,
            $detail,
        );

        /** @var array<string, mixed> $body */
        $body = $response->getData(true);

        return $response->setData([
            ...$body,
            'feature' => $feature,
            'restriction' => $restriction,
            'since' => $since,
        ]);
    }

    /**
     * `404`, con la posibilidad de decir **cual** era el recurso.
     *
     * El detalle es opcional y por defecto sigue siendo el generico, que es lo
     * correcto para la mayoria: enumerar que identificadores existen y cuales no
     * convierte un `404` en un oraculo sobre la plantilla, las credenciales o las
     * incidencias (RS-03, regla dura 17).
     *
     * **Lo usa el asistente de puesta en marcha**, donde no hay nada que
     * proteger: el catalogo de pasos es el mismo en todas las instalaciones
     * (regla dura 13), esta publicado en el contrato y no dice nada de los datos
     * del cliente. Ahi, un `404` mudo obliga a quien pone en marcha el sistema a
     * adivinar si se equivoco de nombre o de version.
     */
    public static function notFound(?string $detail = null): JsonResponse
    {
        return self::response(
            self::TYPE_NOT_FOUND,
            'Recurso no encontrado',
            JsonResponse::HTTP_NOT_FOUND,
            $detail ?? 'No existe el recurso solicitado.',
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

    /**
     * La instalacion esta en mantenimiento: update.sh esta haciendo la copia
     * previa o migrando (RF-PD-10). Nada de lo que se pide se ha perdido: hay
     * que reintentar pasado `Retry-After`. **No dice de que version a cual ni
     * cuanto falta**: eso esta en el informe del servidor, no en una respuesta
     * publica.
     */
    public static function maintenance(int $retryAfterSeconds): JsonResponse
    {
        return self::response(
            self::TYPE_MAINTENANCE,
            'En mantenimiento',
            JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            'La instalacion se esta actualizando. Reintenta pasados unos segundos; nada se ha perdido.',
            headers: ['Retry-After' => (string) max(1, $retryAfterSeconds)],
        );
    }
}
