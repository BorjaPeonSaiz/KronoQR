<?php

declare(strict_types=1);

use App\Exceptions\ProblemDetails;
use App\Http\Middleware\PropagateTraceContext;
use App\Http\Middleware\RecordHttpMetrics;
use App\Modules\Attendance\Application\Exception\EmployeeCannotBeClocked;
use App\Modules\Attendance\Application\Exception\ShiftEntryNotFound;
use App\Modules\Attendance\Application\Port\ShiftEntryHistory;
use App\Modules\Attendance\Domain\Exception\ClockOutBeforeClockIn;
use App\Modules\Attendance\Domain\Exception\CorrectionChangesNothing;
use App\Modules\Attendance\Domain\Exception\CorrectionWouldChangeWorkDate;
use App\Modules\Attendance\Domain\Exception\InvalidCorrectionReason;
use App\Modules\Attendance\Domain\Exception\OverlappingShiftEntry;
use App\Modules\Attendance\Domain\Exception\ShiftAlreadyOpen;
use App\Modules\Compliance\Domain\Exception\InvalidLegalExportRequest;
use App\Modules\Identity\Application\Exception\AccountTemporarilyLocked;
use App\Modules\Identity\Application\Exception\AuthenticationFailed;
use App\Modules\Identity\Application\Exception\PortalAccessDenied;
use App\Modules\Identity\Domain\Exception\CredentialAlreadyDelivered;
use App\Modules\Identity\Domain\Exception\CredentialAlreadyPrinted;
use App\Modules\Identity\Domain\Exception\CredentialAlreadyRevoked;
use App\Modules\Identity\Domain\Exception\CredentialNotPrintedYet;
use App\Modules\Identity\Domain\Exception\CredentialRevocationNeedsReason;
use App\Modules\Identity\Domain\Exception\EmployeeAlreadyHasCredential;
use App\Modules\Identity\Domain\Exception\InvalidSigningKey;
use App\Modules\Reporting\Application\Exception\EmployeeNotFound;
use App\Modules\Reporting\Domain\Exception\InvalidDateRange;
use App\Modules\Workforce\Domain\Exception\DepartmentNotInSite;
use App\Modules\Workforce\Domain\Exception\EmployeeAlreadyTerminated;
use App\Modules\Workforce\Domain\Exception\InvalidEmploymentPeriod;
use App\Modules\Workforce\Domain\Exception\UnknownTimezone;
use App\Modules\Workforce\Domain\Exception\WorkforceConflict;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // La version va en la ruta, no en una cabecera (ADR-012). Todo endpoint
        // del producto cuelga de /api/v1, incluidas las dos sondas de salud.
        api: __DIR__.'/../routes/api_v1.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        // Sin rutas web: las tres aplicaciones cliente son SPA servidas por
        // Nginx, y el backend solo expone API. Sin sonda /up del esqueleto: las
        // del producto son GET /api/v1/health y GET /api/v1/ready (doc 01
        // Anexo B), y su forma la fija el contrato OpenAPI antes que el codigo
        // (ADR-013).
        //
        // SIN `throttleApi()`, y no es una omision: anadirlo pondria `throttle`
        // en TODAS las rutas del grupo `api`, tambien en las dos sondas, y el
        // limitador cuenta en la cache —Redis en produccion—. Una sonda de VIDA
        // que consultara Redis para saber si puede responder haria que Docker
        // reiniciara PHP cuando el que se cae es Redis. Cada ruta declara su
        // zona de limite en routes/api_v1.php.
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Los alias de Sanctum para comprobar el AMBITO del token (doc 02 §7.3).
         *
         * Son la mitad de la autorizacion y la otra mitad es la policy (regla
         * dura 18): el ambito dice QUE puede hacer un token, la policy SOBRE QUE
         * datos. Con las dos, un token de quiosco robado no alcanza los
         * endpoints de gestion aunque su portador tuviera rol, y un rol sin
         * ambito tampoco pasa.
         *
         * Laravel no los registra por defecto desde la version 11: sin estas dos
         * lineas, `->middleware('ability:...')` fallaria con «middleware no
         * definido», que al menos es ruidoso; lo peligroso seria escribirlo mal
         * y que no se aplicara.
         */
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        /*
         * Observabilidad del borde HTTP (doc 02 §8.1 y §8.2, tarea 1.7).
         *
         * Los dos van en el grupo `api`, que es el unico que existe: las tres
         * aplicaciones cliente son SPA y el backend solo expone API.
         *
         * ORDEN DELIBERADO. La propagacion va **primero** para que cualquier span
         * que abra el resto del pipeline —incluidos los del fichaje— cuelgue de la
         * traza que empezo el navegador del quiosco. Si fuera al reves, el propio
         * middleware de metricas quedaria fuera de la traza que intenta describir.
         *
         * NINGUNO DE LOS DOS PUEDE TUMBAR UNA PETICION. Los dos envuelven su
         * trabajo y siguen adelante ante cualquier fallo: medir un fichaje no
         * puede impedirlo (regla dura 19).
         */
        $middleware->api(prepend: [
            PropagateTraceContext::class,
            RecordHttpMetrics::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Toda respuesta de error del producto es `application/problem+json`
         * (RFC 9457), y eso incluye las que genera el framework antes de llegar
         * a un controlador: validacion, autenticacion, autorizacion, ruta
         * inexistente y limite de peticiones.
         *
         * Se registra aqui —y no en un modulo— porque son excepciones del
         * framework y porque `bootstrap/app.php` no puede depender de ningun
         * modulo (Deptrac). Las excepciones de dominio las traduce cada modulo
         * en su ServiceProvider.
         */
        $exceptions->shouldRenderJsonWhen(
            // La API no tiene vistas: cualquier error se responde en JSON, con o
            // sin cabecera `Accept`. Un HTML de error en un cliente que espera
            // JSON se convierte en «error de parseo» y esconde la causa.
            static fn (Request $request, Throwable $exception): bool => true,
        );

        /*
         * Campos que NUNCA acompañan a un informe de excepcion (regla dura 21,
         * ADR-020).
         *
         * `password` y `password_confirmation` son los de serie de Laravel. Los
         * otros dos son de este producto y valen tanto como una contrasena:
         * `qr_payload` es lo que hay impreso en una tarjeta y `pin_sealed` es el
         * PIN de alguien —cerrado hoy, pero cerrado con una clave que vive en el
         * mismo servidor que el volcado—. El historico de errores viaja al
         * fabricante dentro del paquete de diagnostico: si lleva credenciales, se
         * han filtrado.
         */
        $exceptions->dontFlash(['password', 'password_confirmation', 'qr_payload', 'pin_sealed']);

        $exceptions->render(static fn (ValidationException $exception): mixed => ProblemDetails::validationFailed($exception->errors()));

        $exceptions->render(static fn (AuthenticationException $exception): mixed => ProblemDetails::unauthenticated());

        $exceptions->render(static fn (AuthorizationException $exception): mixed => ProblemDetails::forbidden());

        $exceptions->render(static fn (AccessDeniedHttpException $exception): mixed => ProblemDetails::forbidden());

        $exceptions->render(static fn (ModelNotFoundException $exception): mixed => ProblemDetails::notFound());

        $exceptions->render(static fn (NotFoundHttpException $exception): mixed => ProblemDetails::notFound());

        $exceptions->render(static function (ThrottleRequestsException $exception): mixed {
            $retryAfter = $exception->getHeaders()['Retry-After'] ?? '60';

            return ProblemDetails::tooManyRequests((int) $retryAfter);
        });

        /*
         * Y las excepciones de dominio de cada modulo.
         *
         * **Por que aqui y no en el ServiceProvider de cada modulo.** Se intento
         * primero, y no funciona de forma fiable: en la suite de pruebas el
         * manejador de excepciones lo envuelve el de Collision, que no expone
         * `renderable()`, asi que la traduccion se habria comportado distinto en
         * pruebas y en produccion. Un middleware tampoco sirve: el pipeline de
         * enrutado de Laravel convierte la excepcion en respuesta ANTES de que
         * vuelva por los middlewares, asi que nunca la veria.
         *
         * **Por que no rompe la frontera del §1.6.** Deptrac analiza `app/`, y
         * este fichero —como `routes/api_v1.php`, que ya nombra controladores de
         * modulo— es la RAIZ DE COMPOSICION: el sitio cuyo trabajo es conocer
         * las piezas y unirlas. Lo que la regla prohibe es que una clase de
         * `App\` alcance un modulo, y eso se mantiene: `ProblemDetails` no
         * conoce a nadie.
         *
         * Dos codigos y no uno, porque al cliente le cambian la accion
         * siguiente: `409` obliga a releer el recurso y `422` a corregir el
         * formulario.
         */
        $exceptions->render(static fn (AuthenticationFailed $exception): mixed => ProblemDetails::invalidCredentials());

        $exceptions->render(static fn (AccountTemporarilyLocked $exception): mixed => ProblemDetails::tooManyRequests($exception->retryAfterSeconds));

        /*
         * Portal del empleado (tarea 1.11, RF-ID-06, RS-03, RS-12).
         *
         * `401` PARA LAS CINCO CAUSAS, y sin `Retry-After` ni cuando el bloqueo
         * por intentos esta activo. Es lo contrario de lo que hace el acceso al
         * panel dos lineas mas arriba, y a proposito: alli quien entra ya sabe
         * que su cuenta existe —tiene correo de empresa— y decirle cuanto falta
         * le ahorra una llamada a soporte; aqui la mitad publica de la
         * credencial es un codigo de empleado impreso en una tarjeta que se
         * lleva colgada del cuello, y anunciar un bloqueo confirmaria que ese
         * codigo existe (RS-03, regla dura 17). El detalle real —rechazo o
         * bloqueo, y cuantos segundos faltan— queda en el log del servidor.
         */
        $exceptions->render(static fn (PortalAccessDenied $exception): mixed => ProblemDetails::invalidCredentials(
            $exception->getMessage(),
        ));

        $exceptions->render(static fn (EmployeeAlreadyTerminated $exception): mixed => ProblemDetails::conflict($exception->getMessage()));

        $exceptions->render(static fn (WorkforceConflict $exception): mixed => ProblemDetails::conflict($exception->getMessage()));

        $exceptions->render(static fn (DepartmentNotInSite $exception): mixed => ProblemDetails::validationFailed([
            'department_id' => ['El departamento no pertenece al centro indicado.'],
        ]));

        $exceptions->render(static fn (UnknownTimezone $exception): mixed => ProblemDetails::validationFailed([
            'timezone' => ['La zona horaria no existe. Usa un identificador IANA como Europe/Madrid.'],
        ]));

        $exceptions->render(static fn (InvalidEmploymentPeriod $exception): mixed => ProblemDetails::validationFailed([
            'terminated_at' => ['La fecha de cese no puede ser anterior a la de alta.'],
        ]));

        /*
         * Credenciales (tareas 1.5 y 1.10).
         *
         * TODOS LOS CONFLICTOS SON `409` porque todos obligan a releer el recurso
         * antes de reintentar: hay una tarjeta activa que revocar o reemitir, la
         * revocacion ya la hizo alguien, la tarjeta ya se imprimio, ya se
         * entrego, o todavia no se ha impreso.
         *
         * `CredentialAlreadyPrinted` es el mas importante de los cinco y por eso
         * conviene decir que significa: **no hay reimpresion** (ADR-034). El
         * token nace al imprimir y no se puede volver a leer, asi que «imprimir
         * otra vez» solo puede significar «acuñar otro token», y eso deja muerta
         * la tarjeta que quiza ya esta en un bolsillo. La misma excepcion cubre
         * la carrera —dos impresiones a la vez— porque el desenlace para quien
         * llama es identico: esa credencial ya esta impresa.
         *
         * `CredentialNotPrintedYet` es `409` y no `422`: quien lo recibe no tiene
         * que corregir ningun campo —el cuerpo esta vacio—, tiene que imprimir la
         * tarjeta antes de poder registrar su entrega.
         *
         * `InvalidSigningKey` es `503` y no `500`: la instalacion no tiene clave
         * de firma configurada, que es un problema de puesta en marcha y se
         * arregla en el servidor, no en el cliente. El mensaje que sale **no
         * incluye la clave**, solo dice que falta. Desde ADR-034 este error se
         * encuentra al IMPRIMIR y no al emitir, que es donde de verdad hace falta
         * una clave.
         */
        $exceptions->render(static fn (EmployeeAlreadyHasCredential $exception): mixed => ProblemDetails::conflict($exception->getMessage()));

        $exceptions->render(static fn (CredentialAlreadyRevoked $exception): mixed => ProblemDetails::conflict($exception->getMessage()));

        $exceptions->render(static fn (CredentialAlreadyPrinted $exception): mixed => ProblemDetails::conflict($exception->getMessage()));

        $exceptions->render(static fn (CredentialAlreadyDelivered $exception): mixed => ProblemDetails::conflict($exception->getMessage()));

        $exceptions->render(static fn (CredentialNotPrintedYet $exception): mixed => ProblemDetails::conflict($exception->getMessage()));

        $exceptions->render(static fn (CredentialRevocationNeedsReason $exception): mixed => ProblemDetails::validationFailed([
            'reason' => ['Una revocacion tiene que declarar su motivo.'],
        ]));

        $exceptions->render(static fn (InvalidSigningKey $exception): mixed => ProblemDetails::serviceUnavailable(
            'La instalacion no tiene una clave de firma de credenciales valida. Revisa la configuracion del servidor.'
        ));

        /*
         * Correcciones trazadas (tarea 1.15, RF-PA-04, RN-13, ADR-035).
         *
         * TRES CODIGOS Y NO UNO, porque a quien corrige le cambian la accion
         * siguiente:
         *
         *   404  ese tramo no existe. Se equivoco de identificador.
         *   409  existio y ya no es la version vigente, o la correccion chocaria
         *        con otro tramo. Hay que releer la jornada y volver a mirar.
         *   422  lo que pide es imposible o no cambia nada. Se corrige en el
         *        formulario, sin releer nada.
         *
         * EL 404 Y EL 409 SALEN DE LA MISMA EXCEPCION y los separa el historico,
         * que es donde esta la diferencia (ADR-035): `WorkDayRepository` devuelve
         * `null` tanto para un tramo inexistente como para uno ya anulado o
         * sustituido, porque en ninguno de los dos casos hay jornada que lo
         * corrija (ADR-026). Decirle `404` al segundo seria mentirle a quien
         * llego un segundo tarde en un cambio de turno: ese tramo existe, lo que
         * pasa es que otro responsable lo toco antes.
         */
        $exceptions->render(static function (ShiftEntryNotFound $exception): mixed {
            if (! app(ShiftEntryHistory::class)->isRetired($exception->shiftEntryUuid)) {
                return ProblemDetails::notFound();
            }

            return ProblemDetails::conflict(
                'Ese tramo ya no es la version vigente: lo corrigio o lo anulo alguien antes. '
                .'Vuelve a cargar la jornada antes de repetir la operacion.'
            );
        });

        /*
         * RN-01, RN-02 y RN-03 vistas desde el panel. Son `409` las dos primeras
         * porque el problema no esta en los campos que se enviaron: esta en lo
         * que hay registrado, y para arreglarlo hay que mirar la jornada.
         *
         * LAS TRES ESTAN ACOTADAS A LAS RUTAS DE CORRECCION, Y ESO NO ES UN
         * DETALLE. Las mismas tres excepciones las produce tambien el camino de
         * fichaje —`RegisterScanHandler` las reintenta y, agotados los intentos,
         * las relanza— y alli **no pueden salir con este texto**: la regla dura
         * 17 y RS-03 exigen que el quiosco reciba un rechazo generico y de tiempo
         * constante, sin ninguna pista de la causa. Un `409` diciendo «esa
         * persona ya tiene un turno abierto» delante de una tablet colgada en un
         * pasillo es exactamente lo que esa regla prohibe.
         *
         * Devolver `null` deja que el manejador siga su camino, que en el escaneo
         * es lo correcto: agotar tres reintentos por colision es un fallo del
         * servidor, no una peticion mal hecha.
         */
        $exceptions->render(static fn (ShiftAlreadyOpen $exception, Request $request): mixed => $request->routeIs('attendance.shift-entries.*')
            ? ProblemDetails::conflict('Esa persona ya tiene un turno abierto. Cierralo o anulalo antes de dejar otro sin salida.')
            : null);

        $exceptions->render(static fn (OverlappingShiftEntry $exception, Request $request): mixed => $request->routeIs('attendance.shift-entries.*')
            ? ProblemDetails::conflict('Las horas indicadas se solapan con otro tramo de esa persona. Revisa la jornada antes de corregir.')
            : null);

        /*
         * Un `PATCH` que no cambia nada. `422` y no `409`: lo que hay registrado
         * esta bien, lo que sobra es la peticion. Escribir una fila en
         * `shift_corrections` diciendo que se corrigio algo seria una entrada de
         * auditoria que miente.
         */
        $exceptions->render(static fn (CorrectionChangesNothing $exception): mixed => ProblemDetails::validationFailed([
            'clocked_in_at' => ['Las marcas enviadas son las que el tramo ya tiene: no hay nada que corregir.'],
        ]));

        /*
         * ADR-035, decision 2. El texto **dice que hacer**: si solo dijera «no se
         * puede», quien corrige abriria una incidencia de soporte en vez de
         * resolverlo. Mover horas de un dia a otro son dos actos separados y
         * auditados, no un efecto lateral de un `PATCH` (RN-05, regla dura 4).
         */
        $exceptions->render(static fn (CorrectionWouldChangeWorkDate $exception): mixed => ProblemDetails::validationFailed([
            'clocked_in_at' => [
                'Esa hora de entrada llevaria la jornada a otro dia. Para mover las horas de un dia a otro, '
                .'anula el tramo en la jornada de origen y dalo de alta en la de destino: son dos acciones, '
                .'cada una con su motivo.',
            ],
        ]));

        /*
         * RN-03: la salida es posterior a la entrada. Estrictamente — un tramo de
         * duracion cero no es un tramo. Acotada a las rutas de correccion por lo
         * dicho arriba.
         */
        $exceptions->render(static fn (ClockOutBeforeClockIn $exception, Request $request): mixed => $request->routeIs('attendance.shift-entries.*')
            ? ProblemDetails::validationFailed([
                'clocked_out_at' => ['La hora de salida tiene que ser posterior a la de entrada.'],
            ])
            : null);

        /*
         * Un motivo fuera del Anexo C, o un `OTROS` sin explicacion. El
         * `FormRequest` lo atrapa antes con el campo señalado; esto cubre el
         * camino que no pasa por el —una orden construida desde un comando de
         * consola o una prueba— para que no salga como `500`.
         */
        $exceptions->render(static fn (InvalidCorrectionReason $exception): mixed => ProblemDetails::validationFailed([
            'reason_code' => [$exception->getMessage()],
        ]));

        /*
         * Consulta del registro horario (tarea 1.16, RF-PA-03).
         *
         * DOS CODIGOS Y NINGUNO ES UN 200 VACIO. Un empleado que no existe es un
         * `404`, porque «esta persona no existe» y «esta persona no trabajo esos
         * dias» son dos hechos distintos y un panel que los confundiera enseñaria
         * una jornada en blanco a quien escribio mal el identificador.
         *
         * NO HAY RIESGO DE ENUMERACION EN ESE 404. La regla dura 17 —rechazos
         * genericos e indistinguibles— protege el camino de FICHAJE, donde quien
         * escanea es cualquiera delante de una pantalla en un pasillo. Aqui quien
         * llama es una cuenta de gestion que ya puede listar la plantilla entera
         * por `GET /employees`.
         *
         * Y un rango imposible —invertido, con una fecha que no existe o mas
         * ancho que el techo de 366 dias— es un `422`: se corrige en la peticion
         * y sin releer nada. El `FormRequest` lo atrapa antes con el campo
         * señalado; esto cubre el camino que no pasa por el —el rango resuelto
         * por omision, un comando de consola— para que no salga como `500`.
         */
        $exceptions->render(static fn (EmployeeNotFound $exception): mixed => ProblemDetails::notFound());

        $exceptions->render(static fn (InvalidDateRange $exception): mixed => ProblemDetails::validationFailed([
            'from' => [$exception->getMessage()],
        ]));

        /*
         * RN-14 visto desde el alta manual: no se pueden escribir horas a nombre
         * de quien no existe o esta de baja. **Aqui si se puede decir por que**,
         * al contrario que en el quiosco: quien recibe la respuesta es un
         * responsable autenticado en el panel, no una pantalla en un pasillo
         * (RS-03 y la regla dura 17 hablan del escaneo).
         */
        $exceptions->render(static fn (EmployeeCannotBeClocked $exception): mixed => ProblemDetails::validationFailed([
            'employee_uuid' => ['Esa persona no existe o no esta en alta: no se le pueden registrar horas.'],
        ]));

        /*
         * Exportacion legal (tarea 1.17, RF-IN-05, RL-06).
         *
         * `422` y no `500`: un periodo invertido o una fecha que no existe
         * —`2026-02-31`— son peticiones mal hechas, y quien las hizo puede
         * corregirlas sin releer nada. El `FormRequest` las atrapa antes con el
         * campo señalado; esto cubre el camino que no pasa por el, que es el
         * comando de consola del runbook de Inspeccion.
         *
         * **El periodo no se «arregla» dando la vuelta a las fechas**, y por eso
         * el dominio rompe en vez de corregir: el fichero que acabaria en un
         * expediente llevaria escrito un periodo que nadie pidio.
         */
        $exceptions->render(static fn (InvalidLegalExportRequest $exception): mixed => ProblemDetails::validationFailed([
            'from' => [$exception->getMessage()],
        ]));
    })->create();
