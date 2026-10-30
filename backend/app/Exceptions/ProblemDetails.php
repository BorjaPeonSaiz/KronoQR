<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
 * generica y de tiempo constante (regla dura 17), y no puede
 * construirse con una funcion que acepta `detail` libre.
 */
final class ProblemDetails
{
    public const string TYPE_VALIDATION_FAILED = 'urn:kronoqr:problem:validation-failed';

    public const string TYPE_UNAUTHENTICATED = 'urn:kronoqr:problem:unauthenticated';

    public const string TYPE_INVALID_CREDENTIALS = 'urn:kronoqr:problem:invalid-credentials';

    public const string TYPE_FORBIDDEN = 'urn:kronoqr:problem:forbidden';

    /** Sesion con contrasena temporal: solo puede cambiarla (RF-ID-10, ADR-051). */
    public const string TYPE_PASSWORD_CHANGE_REQUIRED = 'urn:kronoqr:problem:password-change-required';

    public const string TYPE_NOT_FOUND = 'urn:kronoqr:problem:not-found';

    public const string TYPE_CONFLICT = 'urn:kronoqr:problem:conflict';

    public const string TYPE_TOO_MANY_REQUESTS = 'urn:kronoqr:problem:too-many-requests';

    /**
     * Bloqueo por origen del acceso al portal (RS-12, ADR-050 §2). Habla de la
     * red de quien pregunta, no de ninguna credencial: se responde asi tambien
     * con el PIN correcto.
     */
    public const string TYPE_PORTAL_ORIGIN_LOCKED = 'urn:kronoqr:problem:portal-origin-locked';

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
     * Todas las composiciones de PDF simultaneas estan ocupadas
     * (`reporting.pdf.max_concurrent`).
     *
     * **Tipo propio y no `TYPE_SERVICE_UNAVAILABLE`**, aunque los dos sean `503`
     * en las mismas rutas: aquel dice «a esta instalacion le falta el motor,
     * avisa a quien administra» y este «el motor esta, pero ocupado: reintenta
     * pasado `Retry-After` o descargalo ya en CSV».
     */
    public const string TYPE_PDF_RENDERING_BUSY = 'urn:kronoqr:problem:pdf-rendering-busy';

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
     * Ya hay una exportacion integra `pending` o `running` (**RF-PD-14**).
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
     * Quien pide ya tiene un informe en diferido `pending` o `running`
     * (**RF-IN-06**).
     *
     * **Tipo propio y no `TYPE_CONFLICT`**, aunque los dos sean `409`, y **tipo
     * distinto del de la exportacion integra** aunque se parezcan: aquel dice
     * «la instalacion esta ocupada» y este dice «**tu** ya tienes uno en curso».
     * La accion que le toca a quien lo recibe es la misma —esperar— pero el
     * mensaje no: dos responsables generando a la vez no se estorban, y decirle a
     * uno que espere por el otro seria falso.
     *
     * El panel enseña esa exportacion en lugar de pedir otra, y para eso la fila
     * viaja en el cuerpo.
     */
    public const string TYPE_REPORT_EXPORT_IN_PROGRESS = 'urn:kronoqr:problem:report-export-in-progress';

    /**
     * Se pidio cancelar un informe en diferido que ya termino.
     *
     * `409` y no `422`: la peticion esta bien formada, lo que no admite es el
     * estado de la fila —`completed`, `failed` o `purged`—. El cuerpo lleva la
     * fila en `export` para que la pantalla la enseñe tal como esta: si el
     * trabajo la cerro un instante antes que la cancelacion, el fichero se puede
     * descargar.
     */
    public const string TYPE_REPORT_EXPORT_NOT_CANCELLABLE = 'urn:kronoqr:problem:report-export-not-cancellable';

    /**
     * El informe no cabe en una respuesta sincrona (RNF-P-05, RF-IN-06).
     *
     * **`422` con `type` propio, y no el de validacion generico.** Sigue siendo un
     * `422` —el servicio esta bien, lo que no cabe es esa peticion— pero es un
     * desenlace distinto de «has escrito mal una fecha»: aqui no hay nada que
     * corregir, hay que **generar el informe en segundo plano**
     * (`POST /api/v1/reports/exports`).
     *
     * Antes salia como `urn:kronoqr:problem:validation-failed` y el panel lo
     * distinguia **analizando una frase en castellano** del `detail`. Eso se rompe
     * el dia que alguien mejore la redaccion, y no funciona en ingles: el `type`
     * existe exactamente para que un cliente pueda reaccionar sin leer prosa.
     *
     * El cuerpo no cambia —`ValidationProblem` con `errors.to`— para que el
     * cliente generado no necesite una forma nueva.
     */
    public const string TYPE_REPORT_TOO_LARGE = 'urn:kronoqr:problem:report-too-large';

    /**
     * El enlace de descarga de un informe en diferido **ya se uso**
     * (**RF-IN-06**, ADR-041).
     *
     * `410 Gone` y no `404`, porque a quien lo recibe le cambia la accion: el
     * fichero **sigue existiendo** mientras no caduque, y la salida es volver a la
     * pantalla de informes y pedir otro enlace. Un `404` diria «esto no existe,
     * deja de intentarlo».
     *
     * Sale al repetir la misma URL —volver atras en el navegador, reenviarla— y es
     * la prueba de que el enlace es de un solo uso de verdad.
     */
    public const string TYPE_REPORT_EXPORT_LINK_USED = 'urn:kronoqr:problem:report-export-link-used';

    /**
     * El enlace de descarga de un informe en diferido **ha caducado**
     * (**RF-IN-06**, ADR-041).
     *
     * `410` por lo mismo que el anterior, y **tipo distinto** porque la causa que
     * se le cuenta a quien lo recibe es otra: ahi gasto el enlace y aqui se le paso
     * el plazo de `REPORTING_EXPORT_LINK_TTL_MINUTES`. Distinguirlos no es un
     * oraculo —quien llega ha presentado un `uuid` v7 correcto, que solo tiene
     * quien recibio el enlace— y es la diferencia entre «vuelve a pulsar» y «se te
     * ha quedado la pestaña abierta demasiado tiempo».
     */
    public const string TYPE_REPORT_EXPORT_LINK_EXPIRED = 'urn:kronoqr:problem:report-export-link-expired';

    /**
     * El `{uuid}` de la correccion existio y **ya no es la version vigente**:
     * otra persona lo corrigio o lo anulo antes (ADR-035).
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
     * La persona **ya esta dada de baja**: su ficha no se modifica, no se da de
     * baja otra vez y su PIN no se restablece ni se entrega (RN-14, ADR-046).
     *
     * **Tipo propio y no `TYPE_CONFLICT`**, aunque los dos sean `409`, porque el
     * mismo endpoint devuelve ambos y a quien los recibe le cambia la accion
     * siguiente: el generico —un correo ya usado por otra persona— se corrige
     * en el formulario sin perder lo escrito, y este dice «la ficha ya no se
     * puede tocar: recargala». Distinguirlos por el `detail` es lo que el
     * `type` existe para evitar.
     */
    public const string TYPE_EMPLOYEE_TERMINATED = 'urn:kronoqr:problem:employee-terminated';

    /**
     * **La zona horaria del centro ya esta fija**: existe registro horario y la
     * peticion pedia otra zona (RN-23, ADR-056). No ha cambiado nada, tampoco el
     * nombre si viajaba en la misma peticion.
     *
     * **Tipo propio y no `TYPE_CONFLICT`**, aunque los dos sean `409` en `PATCH
     * /site`, porque al panel le cambia la accion: el generico —un nombre ya
     * usado— se corrige en el formulario; este dice «la zona no se cambia desde
     * aqui: relee el centro», que vendra con `timezone_locked: true`.
     */
    public const string TYPE_SITE_TIME_ZONE_LOCKED = 'urn:kronoqr:problem:site-time-zone-locked';

    /**
     * **La fecha de cese de una baja programada ya ha pasado** (RN-14, ADR-062
     * §3): la baja rige aunque la tarea no la haya aplicado, y ni se anula ni
     * cambia de fecha. Tipo propio porque la accion siguiente no es corregir el
     * formulario: es readmitir o corregir la fecha cuando la baja sea efectiva.
     */
    public const string TYPE_OFFBOARDING_DUE = 'urn:kronoqr:problem:offboarding-due';

    /**
     * Se pide anular una baja programada que no existe (RN-14): no hay nada que
     * anular, y se recarga la ficha.
     */
    public const string TYPE_OFFBOARDING_NOT_SCHEDULED = 'urn:kronoqr:problem:offboarding-not-scheduled';

    /**
     * Se pide readmitir a quien no esta de baja (RN-27, ADR-062 §6): sigue en
     * alta, suspendida o con una baja solo programada. Se recarga la ficha.
     */
    public const string TYPE_EMPLOYEE_NOT_TERMINATED = 'urn:kronoqr:problem:employee-not-terminated';

    /**
     * La ficha esta minimizada por retencion (ADR-060): no admite contratos ni
     * ausencias nuevos, y su vida laboral ya no cambia: ni readmision ni
     * correccion de la fecha de cese (RN-27). Quien vuelve es un alta nueva.
     */
    public const string TYPE_EMPLOYEE_RECORD_MINIMISED = 'urn:kronoqr:problem:employee-record-minimised';

    /**
     * Se pide corregir la fecha de cese de un periodo sin baja efectiva (RN-27,
     * ADR-062 §7): una baja programada se reprograma desde la baja.
     */
    public const string TYPE_OFFBOARDING_NOT_EFFECTIVE = 'urn:kronoqr:problem:offboarding-not-effective';

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
     * El acento de marca nuevo no llega a 4,5:1 sobre las superficies claras y no viene
     * confirmado (`PATCH /api/v1/settings`).
     *
     * `422` con el error colgado de `settings.BRANDING_ACCENT_COLOR`, como una
     * validacion, y `type` propio por el mismo motivo que el de arriba: el panel
     * no pinta un error sin salida junto al selector de color, ofrece «guardar
     * de todos modos» y repite la peticion con `confirm_low_contrast: true`.
     */
    public const string TYPE_LOW_CONTRAST_ACCENT = 'urn:kronoqr:problem:low-contrast-accent';

    /*
     * LOS ERRORES QUE PRODUCE EL FRAMEWORK Y NO UN CASO DE USO.
     *
     * Sin esto saldrian como `application/json {"message": ...}` —o, con
     * `APP_DEBUG=true`, con la traza, el SQL y datos de la plantilla—, y el
     * contrato promete `problem+json` para TODA respuesta de error. Tipos
     * propios y no uno generico porque al cliente le cambia la accion: un `405`
     * o un `415` son un defecto del cliente que no se arregla reintentando; un
     * `500` si puede arreglarse solo.
     */

    /** El metodo no existe para esa ruta. Lleva la cabecera `Allow`. */
    public const string TYPE_METHOD_NOT_ALLOWED = 'urn:kronoqr:problem:method-not-allowed';

    /** El cuerpo supera lo que acepta PHP (`post_max_size`). */
    public const string TYPE_PAYLOAD_TOO_LARGE = 'urn:kronoqr:problem:payload-too-large';

    /** El tipo de medio del cuerpo no es uno que la ruta entienda. */
    public const string TYPE_UNSUPPORTED_MEDIA_TYPE = 'urn:kronoqr:problem:unsupported-media-type';

    /** Token CSRF caducado o ausente en una peticion con sesion (`419`). */
    public const string TYPE_CSRF_TOKEN_MISMATCH = 'urn:kronoqr:problem:csrf-token-mismatch';

    /** Peticion que el framework no puede interpretar (`400` sin detalle por campo). */
    public const string TYPE_BAD_REQUEST = 'urn:kronoqr:problem:bad-request';

    /**
     * Fallo no controlado del servidor.
     *
     * **Nunca lleva el mensaje de la excepcion**, tampoco con `APP_DEBUG=true`:
     * el de una `QueryException` trae el SQL y los valores enlazados, y entre
     * ellos codigos de empleado (regla dura 21). El detalle esta en el log y
     * en `error_events`, agrupado por huella (RF-PD-15).
     */
    public const string TYPE_INTERNAL_ERROR = 'urn:kronoqr:problem:internal-error';

    /** Cualquier otro codigo HTTP de error que el framework emita sin tipo propio. */
    public const string TYPE_HTTP_ERROR = 'urn:kronoqr:problem:http-error';

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
        ?string $instance = null,
    ): JsonResponse {
        $body = [
            'type' => $type,
            'title' => self::localized($title),
            'status' => $status,
            'detail' => self::localized($detail),
        ];

        // Todo `503` dice que ruta fallo (F4a-6): el cliente lo enseña y quien
        // administra lo casa con su linea en el log.
        $instance ??= $status === JsonResponse::HTTP_SERVICE_UNAVAILABLE ? self::currentPath() : null;

        if ($instance !== null) {
            // RFC 9457: la ruta que fallo, sin consulta. Es lo que permite
            // casar la respuesta con su linea en el log del servidor.
            $body['instance'] = $instance;
        }

        if ($errors !== []) {
            // Tambien por campo: los textos fijos de `bootstrap/app.php` estan
            // en español y su ingles en `lang/en.json`, como `title` y `detail`.
            // Los que ya llegan traducidos (los de validacion de Laravel, los
            // de `translated()`) no tienen entrada y salen tal cual.
            $body['errors'] = array_map(
                static fn (array $messages): array => array_map(self::localized(...), $messages),
                $errors,
            );
        }

        return new JsonResponse(
            $body,
            $status,
            // El tipo de medio es parte del contrato: un cliente que espera
            // `problem+json` y recibe `json` no sabe que hacer con el cuerpo. Y
            // el idioma de `title` y `detail`, que es el negociado (F4a-3).
            ['Content-Type' => 'application/problem+json', 'Content-Language' => self::locale(), ...$headers],
        );
    }

    /**
     * El texto fijo de un error, en el idioma negociado por la peticion (F4a-3).
     *
     * Los textos de esta clase estan escritos en español y son la clave: el
     * ingles vive en `lang/en.json`. Un texto que no tiene traduccion —el
     * mensaje de una excepcion de dominio, por ejemplo, que ya llega
     * traducido o en ingles tecnico— sale tal cual.
     */
    private static function localized(string $text): string
    {
        try {
            $translated = __($text);

            return \is_string($translated) && $translated !== '' ? $translated : $text;
        } catch (\Throwable) {
            return $text;
        }
    }

    private static function locale(): string
    {
        try {
            return app()->getLocale();
        } catch (\Throwable) {
            return 'es';
        }
    }

    /** La ruta de la peticion en curso, sin la consulta, o `null` fuera de una. */
    private static function currentPath(): ?string
    {
        try {
            $request = request();

            return '/'.ltrim($request->path(), '/');
        } catch (\Throwable) {
            return null;
        }
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
     * `422` porque el informe no cabe en el acto (RNF-P-05, RF-IN-06).
     *
     * Mismo cuerpo que una validacion —`errors.to` con las cifras concretas— y
     * `type` propio, para que el panel pueda ofrecer «generar en segundo plano»
     * sin analizar una frase en castellano. Ver
     * {@see self::TYPE_REPORT_TOO_LARGE}.
     *
     * **Se señala `to`** porque el rango es lo unico que quien pregunta puede
     * acortar siempre; la granularidad y el departamento no estan en todas las
     * peticiones.
     */
    public static function reportTooLarge(string $detail): JsonResponse
    {
        return self::response(
            self::TYPE_REPORT_TOO_LARGE,
            'El informe no cabe en una respuesta inmediata',
            JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            $detail,
            ['to' => [$detail]],
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
     * (RF-PD-14).
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

    /**
     * `409` al pedir un informe en diferido teniendo ya uno en curso (RF-IN-06).
     *
     * **Recibe un array y no el modelo de dominio**, y no es pereza: esta clase
     * vive fuera de los modulos a proposito —tiene que poder usarse desde
     * `bootstrap/app.php`— y Deptrac le prohibe nombrar un tipo de
     * `App\Modules\*`. Quien la llama es el controlador, que si puede serializar.
     *
     * @param  array<string, mixed>  $export  La fila ya serializada con la forma del contrato.
     */
    public static function reportExportInProgress(string $detail, array $export): JsonResponse
    {
        $response = self::response(
            self::TYPE_REPORT_EXPORT_IN_PROGRESS,
            'Ya tienes un informe en curso',
            JsonResponse::HTTP_CONFLICT,
            $detail,
        );

        /** @var array<string, mixed> $body */
        $body = $response->getData(true);

        return $response->setData([...$body, 'export' => $export]);
    }

    /**
     * `409` al cancelar un informe en diferido que ya termino.
     *
     * @param  array<string, mixed>  $export  La fila tal como esta, con la forma de `ReportExport`.
     */
    public static function reportExportNotCancellable(array $export): JsonResponse
    {
        $response = self::response(
            self::TYPE_REPORT_EXPORT_NOT_CANCELLABLE,
            'Ese informe ya ha terminado',
            JsonResponse::HTTP_CONFLICT,
            'Ya no hay nada que cancelar: el informe termino antes. Si se genero, puedes descargarlo desde esta misma pantalla.',
        );

        /** @var array<string, mixed> $body */
        $body = $response->getData(true);

        return $response->setData([...$body, 'export' => $export]);
    }

    /** `410` al reutilizar un enlace de descarga ya consumido (RF-IN-06, ADR-041). */
    public static function reportExportLinkUsed(): JsonResponse
    {
        return self::response(
            self::TYPE_REPORT_EXPORT_LINK_USED,
            'Ese enlace de descarga ya se ha usado',
            JsonResponse::HTTP_GONE,
            'Cada enlace sirve para una sola descarga. Vuelve a la pantalla de informes y pulsa «Descargar» otra vez.',
        );
    }

    /** `410` cuando el enlace de descarga ha caducado (RF-IN-06, ADR-041). */
    public static function reportExportLinkExpired(): JsonResponse
    {
        return self::response(
            self::TYPE_REPORT_EXPORT_LINK_EXPIRED,
            'Ese enlace de descarga ha caducado',
            JsonResponse::HTTP_GONE,
            'Los enlaces caducan a los pocos minutos. Vuelve a la pantalla de informes y pulsa «Descargar» otra vez.',
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
     * Los tres `409` de la correccion de tramos, cada uno con su `type`.
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

    /** `409` de una persona ya dada de baja (RN-14). {@see self::TYPE_EMPLOYEE_TERMINATED} */
    public static function employeeTerminated(string $detail): JsonResponse
    {
        return self::response(
            self::TYPE_EMPLOYEE_TERMINATED,
            'La persona ya esta dada de baja',
            JsonResponse::HTTP_CONFLICT,
            $detail,
        );
    }

    /** `409` de una baja programada cuyo cese ya paso (RN-14). {@see self::TYPE_OFFBOARDING_DUE} */
    public static function offboardingDue(string $detail): JsonResponse
    {
        return self::response(
            self::TYPE_OFFBOARDING_DUE,
            'La fecha de cese ya ha pasado',
            JsonResponse::HTTP_CONFLICT,
            $detail,
        );
    }

    /** `409` sin baja programada que anular (RN-14). {@see self::TYPE_OFFBOARDING_NOT_SCHEDULED} */
    public static function offboardingNotScheduled(string $detail): JsonResponse
    {
        return self::response(
            self::TYPE_OFFBOARDING_NOT_SCHEDULED,
            'No hay baja programada',
            JsonResponse::HTTP_CONFLICT,
            $detail,
        );
    }

    /** `409` de una readmision de quien no esta de baja (RN-27). {@see self::TYPE_EMPLOYEE_NOT_TERMINATED} */
    public static function employeeNotTerminated(string $detail): JsonResponse
    {
        return self::response(
            self::TYPE_EMPLOYEE_NOT_TERMINATED,
            'La persona no esta de baja',
            JsonResponse::HTTP_CONFLICT,
            $detail,
        );
    }

    /** `409` de una ficha minimizada (RN-27, ADR-060). {@see self::TYPE_EMPLOYEE_RECORD_MINIMISED} */
    public static function employeeRecordMinimised(string $detail): JsonResponse
    {
        return self::response(
            self::TYPE_EMPLOYEE_RECORD_MINIMISED,
            'La ficha esta minimizada',
            JsonResponse::HTTP_CONFLICT,
            $detail,
        );
    }

    /** `409` de una correccion sin baja efectiva (RN-27). {@see self::TYPE_OFFBOARDING_NOT_EFFECTIVE} */
    public static function offboardingNotEffective(string $detail): JsonResponse
    {
        return self::response(
            self::TYPE_OFFBOARDING_NOT_EFFECTIVE,
            'La baja no es efectiva',
            JsonResponse::HTTP_CONFLICT,
            $detail,
        );
    }

    /**
     * `409` del documento ya usado en el alta, con la persona de baja que lo
     * tiene para ofrecer su readmision (RN-27, ADR-062 §6).
     */
    public static function rehirableConflict(string $detail, string $employeeUuid): JsonResponse
    {
        $response = self::conflict($detail);
        /** @var array<string, mixed> $body */
        $body = $response->getData(true);
        $body['rehirable_employee_uuid'] = $employeeUuid;

        return $response->setData($body);
    }

    /** `409` de la zona horaria ya fija (RN-23). {@see self::TYPE_SITE_TIME_ZONE_LOCKED} */
    public static function siteTimezoneLocked(string $detail): JsonResponse
    {
        return self::response(
            self::TYPE_SITE_TIME_ZONE_LOCKED,
            'La zona horaria del centro ya esta fija',
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
     * `$cause` dice por que (RN-05, ADR-058): el panel enseña su propia pista
     * solo cuando se movio la apertura, y el texto del servidor en los demas.
     *
     * @param  array<string, list<string>>  $errors
     * @param  'opening_moved'|'opening_voided'|'entry_not_continued'|'next_entry_not_continued'  $cause
     */
    public static function correctionWouldChangeWorkDate(array $errors, string $cause): JsonResponse
    {
        $response = self::response(
            self::TYPE_CORRECTION_WOULD_CHANGE_WORK_DATE,
            'Peticion no valida',
            JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            'Revisa los campos indicados.',
            $errors,
        );

        /** @var array<string, mixed> $body */
        $body = $response->getData(true);

        return $response->setData([...$body, 'cause' => $cause]);
    }

    /**
     * `422` de MB2: acento sin contraste y sin confirmar. Ver
     * {@see self::TYPE_LOW_CONTRAST_ACCENT}. Mismo cuerpo que
     * `validationFailed()` salvo el `type`.
     *
     * @param  array<string, list<string>>  $errors
     */
    public static function lowContrastAccent(array $errors): JsonResponse
    {
        return self::response(
            self::TYPE_LOW_CONTRAST_ACCENT,
            'Peticion no valida',
            JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            'Revisa los campos indicados.',
            $errors,
        );
    }

    /**
     * Una funcionalidad **accesoria** no esta disponible con esta licencia
     * (ADR-019, ADR-023, RF-PD-05).
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

    /**
     * `403` de una sesion abierta con contrasena **temporal** (RF-ID-10,
     * ADR-051): el token solo lleva `password:change`. No le falta ningun
     * permiso a la persona: le falta fijar su propia contrasena, y el panel lo
     * distingue por el `type` para llevarla a esa pantalla.
     */
    public static function passwordChangeRequired(string $detail): JsonResponse
    {
        return self::response(
            self::TYPE_PASSWORD_CHANGE_REQUIRED,
            'Cambio de contrasena pendiente',
            JsonResponse::HTTP_FORBIDDEN,
            $detail,
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
     * El motor de PDF esta ocupado: `503` con `Retry-After`.
     * {@see self::TYPE_PDF_RENDERING_BUSY}
     *
     * El `detail` dice la salida, que es la que exige RL-05: el mismo registro
     * en CSV no pasa por el motor y se descarga ya.
     */
    public static function pdfRenderingBusy(string $detail, int $retryAfterSeconds): JsonResponse
    {
        return self::response(
            self::TYPE_PDF_RENDERING_BUSY,
            'Motor de PDF ocupado',
            JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            $detail,
            headers: ['Retry-After' => (string) max(1, $retryAfterSeconds)],
        );
    }

    /**
     * Una dependencia —la base de datos o Redis— no responde: `503` con
     * `Retry-After` (ADR-059).
     *
     * Mismo tipo que {@see self::serviceUnavailable()} —a quien lo recibe le
     * toca lo mismo: no es culpa suya, reintentar mas tarde— y con
     * `Retry-After`, que es el plazo del cortacircuitos: antes de ese momento
     * el servidor ni siquiera volvera a intentar la conexion. El quiosco
     * conserva el fichaje en su cola y lo reenvia (regla dura 19).
     *
     * **El detalle es fijo y no nombra nada**: ni base de datos, ni host, ni
     * puerto. Quien administra lo ve en `database.circuit_opened` y en `/ready`.
     */
    public static function dependencyUnavailable(int $retryAfterSeconds, ?string $instance = null): JsonResponse
    {
        return self::response(
            self::TYPE_SERVICE_UNAVAILABLE,
            'Servicio no disponible',
            JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            'El servicio no esta disponible en este momento. Reintenta pasados unos segundos.',
            headers: ['Retry-After' => (string) max(1, $retryAfterSeconds)],
            instance: $instance,
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

    /**
     * Un error HTTP que el framework lanzo sin pasar por ningun caso de uso:
     * `405`, `413`, `415`, `419`, `400`… (F4a-1).
     *
     * Conserva las cabeceras que la excepcion traia —`Allow` en el `405`,
     * `Retry-After` si la hubiera— porque son parte de la respuesta, no del
     * formato. **El `detail` es fijo por codigo**: el mensaje de la excepcion no
     * sale, que en un `405` de desarrollo arrastraba la ruta interna.
     *
     * @param  array<string, string>  $headers
     */
    public static function httpError(int $status, array $headers = []): JsonResponse
    {
        [$type, $title, $detail] = match ($status) {
            JsonResponse::HTTP_BAD_REQUEST => [
                self::TYPE_BAD_REQUEST, 'Peticion mal formada', 'La peticion no se ha podido interpretar.',
            ],
            JsonResponse::HTTP_METHOD_NOT_ALLOWED => [
                self::TYPE_METHOD_NOT_ALLOWED, 'Metodo no permitido', 'Esta ruta no admite ese metodo HTTP.',
            ],
            JsonResponse::HTTP_CONFLICT => [
                self::TYPE_CONFLICT, 'Conflicto con el estado actual', 'La peticion choca con el estado actual del recurso.',
            ],
            JsonResponse::HTTP_REQUEST_ENTITY_TOO_LARGE => [
                self::TYPE_PAYLOAD_TOO_LARGE, 'Peticion demasiado grande', 'El cuerpo de la peticion supera el tamaño admitido.',
            ],
            JsonResponse::HTTP_UNSUPPORTED_MEDIA_TYPE => [
                self::TYPE_UNSUPPORTED_MEDIA_TYPE, 'Tipo de contenido no admitido', 'El tipo de contenido del cuerpo no es uno que esta ruta acepte.',
            ],
            419 => [
                self::TYPE_CSRF_TOKEN_MISMATCH, 'Sesion caducada', 'El token de la sesion ha caducado. Vuelve a cargar e intentalo otra vez.',
            ],
            JsonResponse::HTTP_INTERNAL_SERVER_ERROR => [
                self::TYPE_INTERNAL_ERROR, 'Error interno', 'Se ha producido un error inesperado. Ha quedado registrado.',
            ],
            default => [
                self::TYPE_HTTP_ERROR, 'Error HTTP', 'La peticion no se ha podido atender.',
            ],
        };

        return self::response($type, $title, $status, $detail, headers: $headers);
    }

    /**
     * Las cabeceras de una excepcion HTTP que viajan con su `problem+json`:
     * `Allow`, `Retry-After`… Una lista se une como hace Symfony al enviarla.
     *
     * @return array<string, string>
     */
    public static function headersOf(HttpExceptionInterface $exception): array
    {
        $headers = [];

        foreach ($exception->getHeaders() as $name => $value) {
            $headers[(string) $name] = \is_array($value)
                ? implode(', ', array_map(static fn (mixed $item): string => \is_scalar($item) ? (string) $item : '', $value))
                : (\is_scalar($value) ? (string) $value : '');
        }

        return $headers;
    }

    /**
     * El `500` de cualquier excepcion que ningun `render` especifico traduce.
     * Ver {@see self::TYPE_INTERNAL_ERROR}.
     */
    public static function internalError(): JsonResponse
    {
        return self::httpError(JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
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
     * El portal esta cerrado al origen de la peticion (ADR-050 §2). `detail`
     * fijo: el portal enseña su propio texto a partir de `type`.
     */
    public static function portalOriginLocked(int $retryAfterSeconds): JsonResponse
    {
        return self::response(
            self::TYPE_PORTAL_ORIGIN_LOCKED,
            'Demasiados accesos fallidos',
            JsonResponse::HTTP_TOO_MANY_REQUESTS,
            'Demasiados accesos fallidos desde esta red. Vuelve a intentarlo mas tarde.',
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
