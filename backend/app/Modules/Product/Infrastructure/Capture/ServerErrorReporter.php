<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Capture;

use App\Modules\Shared\Application\Port\ErrorEventSink;
use App\Modules\Shared\Application\Support\SpanScope;
use App\Modules\Shared\Application\Support\TraceparentHeader;
use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorReport;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use Closure;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Los cuatro origenes de servidor del historico de errores, con un solo enganche
 * (RF-PD-15, tarea 5.12, decisiones 1, 2 y 3).
 *
 * Lo invoca el `reportable` que `ProductServiceProvider::registerErrorCapture()`
 * registra sobre el manejador de excepciones. Todo `Throwable` que Laravel
 * informa pasa por aqui; el que sobrevive al filtro se convierte en
 * {@see ErrorReport} y va al {@see ErrorEventSink}.
 *
 * ## Que NO es un error (decision 1, reescrita tras la revision)
 *
 * Un `409` de «turno ya abierto» **es el sistema funcionando**. La primera
 * version lo decidia por el espacio de nombres de la excepcion —todo lo de
 * `Domain\Exception` y `Application\Exception` quedaba fuera— y estaba mal: de
 * las 96 excepciones de esos dos espacios, **50 no tienen `render` en
 * `bootstrap/app.php`** (`AuditPayloadIsNotCanonical`, `InstantIsNotUtc`,
 * `PairingCodeSpaceExhausted`…). Esas producen un `500` de verdad y quedaban
 * fuera del historico: exactamente los fallos que el IT del cliente tiene que
 * ver, invisibles porque su clase estaba en la carpeta correcta.
 *
 * La regla nueva no adivina: **pregunta**.
 *
 * - **Dentro de una peticion HTTP** se le pide al propio manejador de
 *   excepciones que renderice y se mira el estado. `< 500` es un desenlace
 *   esperado —lo diga quien lo diga y venga de donde venga— y no entra. Renderiza
 *   de mas una vez por error, que es un coste despreciable frente a un panel de
 *   errores que miente.
 * - **Fuera de una peticion** (cola, planificador, consola) no hay estado que
 *   mirar y **todo `Throwable` es un error**, salvo los internos que Laravel ni
 *   siquiera informa.
 *
 * Si `render()` lanza, es un error: un manejador que no sabe responder a algo es
 * justo lo que hay que registrar.
 *
 * La lista de internos se repite aqui aunque Laravel ya no informe la mayoria
 * (`Handler::$internalDontReport`) a proposito: **no se depende de una lista
 * ajena** que puede cambiar entre versiones menores, y es la unica defensa en el
 * camino que no pasa por `render()`.
 *
 * ## Capturar no silencia, y capturar no rompe
 *
 * El `reportable` **nunca devuelve `false`**, asi que el informe normal sigue
 * llegando a Monolog: `error_events` complementa el log tecnico, no lo sustituye
 * (§8.2.1). Y todo el metodo va envuelto: una excepcion aqui convertiria un
 * error en dos y dejaria sin registrar el primero (regla dura 19). Si el sink
 * todavia no esta enlazado -o falla-, el error se pierde en la tabla y sigue
 * estando en el log.
 *
 * ## Sin datos personales (regla dura 21)
 *
 * De la peticion solo salen **ruta y metodo**, y de quien la hizo solo su
 * identificador **publico**: el UUID del dispositivo o el del empleado, leidos
 * del portador del token. Nunca un nombre, nunca un correo, nunca el cuerpo. El
 * mensaje de la excepcion viaja crudo porque el saneado es del servidor y ocurre
 * en el sink (decision 5): quien reporta no tiene que saber sanear y no se confia
 * en que lo haya hecho.
 *
 * **Con una excepcion, y es la que costo una revision**: el mensaje de una
 * `QueryException` **no se usa**. Laravel lo compone interpolando los parametros
 * enlazados en el SQL y **sin comillas** (`values (Maria, Gonzalez Perez,
 * EMP-0042, $2y$12$…)`), asi que el saneado por comillas del sink no los ve y a
 * la tabla llegaban nombres, codigos de empleado y el hash de un PIN en claro.
 * Ver {@see self::messageOf()}.
 */
final readonly class ServerErrorReporter
{
    /**
     * Excepciones del framework que son desenlaces esperados y no averias.
     *
     * @var list<class-string<Throwable>>
     */
    private const array EXPECTED_FRAMEWORK_EXCEPTIONS = [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        ModelNotFoundException::class,
        RecordsNotFoundException::class,
        MultipleRecordsFoundException::class,
        ThrottleRequestsException::class,
        TokenMismatchException::class,
        HttpResponseException::class,
    ];

    /**
     * Separador entre el mensaje del driver y el SQL con sus marcadores.
     *
     * **No es `, SQL: `** —el que usa Laravel— a proposito: el saneado del sink
     * corta por esa cadena como red de seguridad, porque tras ella Laravel deja
     * los valores enlazados. Aqui despues del separador va el SQL **sin valores**,
     * que es lo unico que hace diagnosticable un fallo de base de datos, y con
     * una marca propia sobrevive a esa red.
     */
    private const string SQL_SEPARATOR = ' | sql: ';

    /**
     * @param  Closure(): ErrorEventSink  $sink  Perezoso: el historico se resuelve cuando hay algo que guardar, no al arrancar.
     * @param  Closure(): ?Request  $request  La peticion en curso, o `null` fuera de una (cola, planificador, consola).
     * @param  Closure(): ExceptionHandler  $handler  El manejador al que se le pregunta que estado responderia. Perezoso: se resuelve dentro de su propia devolucion.
     * @param  string  $basePath  Raiz del proyecto, para que `file` sea relativa y no delate el arbol del servidor.
     */
    public function __construct(
        private ExecutionContext $context,
        private Closure $sink,
        private Closure $request,
        private Closure $handler,
        private string $appVersion,
        private string $basePath,
    ) {}

    /**
     * El enganche. No lanza, no devuelve `false` y no toca la excepcion.
     */
    public function report(Throwable $exception): void
    {
        try {
            $request = ($this->request)();
            $frame = $this->context->current();

            // Sin marco de ejecucion y con peticion en curso, estamos DENTRO de
            // una peticion HTTP: es el unico caso en el que hay un estado que
            // preguntar. Con marco (cola, planificador, consola) no lo hay.
            $inRequest = $frame === null && $request instanceof Request;

            if ($this->isExpectedOutcome($exception, $inRequest ? $request : null)) {
                return;
            }

            ($this->sink)()->record($this->toReport($exception, $frame ?? $this->requestFrame($request), $request));
        } catch (Throwable) {
            // Ver el docblock: un error al guardar el error no puede convertirse
            // en un segundo error ni interrumpir el informe a Monolog.
        }
    }

    /**
     * @param  Request|null  $request  La peticion en curso, o `null` si esto no ocurre dentro de una.
     */
    private function isExpectedOutcome(Throwable $exception, ?Request $request): bool
    {
        foreach (self::EXPECTED_FRAMEWORK_EXCEPTIONS as $expected) {
            if ($exception instanceof $expected) {
                return true;
            }
        }

        // Quien lanza una `HttpException` de `4xx` esta diciendo «esta peticion
        // esta mal», no «el sistema esta roto». Vale dentro y fuera de HTTP, y
        // ahorra el renderizado en el caso mas comun.
        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return true;
        }

        if (! $request instanceof Request) {
            // Fuera de una peticion todo lo demas es error (decision 1).
            return false;
        }

        return $this->renderedStatus($request, $exception) < 500;
    }

    /**
     * Que estado responderia el manejador a esta excepcion.
     *
     * **Se pregunta en vez de deducirlo.** La traduccion de cada excepcion de
     * dominio a `409`, `422` o `403` vive en `bootstrap/app.php` y cambia cuando
     * un modulo anade la suya; cualquier copia de esa lista aqui nace obsoleta.
     *
     * Renderizar dos veces es seguro porque `render()` **no informa**: compone
     * una respuesta a partir de la excepcion y nada mas. Las devoluciones del
     * producto devuelven `ProblemDetails`, que no escribe en ninguna parte —el
     * asiento de `AccessOutOfScope`, por ejemplo, lo escribe `ScopeGuard` antes
     * de lanzar—, y una prueba comprueba que la respuesta final no cambia por
     * tener la captacion encendida.
     *
     * Si `render()` lanza, `500`: un manejador que no sabe responder a algo es
     * justo lo que hay que registrar.
     */
    private function renderedStatus(Request $request, Throwable $exception): int
    {
        try {
            return ($this->handler)()->render($request, $exception)->getStatusCode();
        } catch (Throwable) {
            return 500;
        }
    }

    private function toReport(Throwable $exception, ExecutionFrame $frame, ?Request $request): ErrorReport
    {
        return new ErrorReport(
            source: $frame->source,
            level: $frame->critical ? ErrorLevel::Critical : ErrorLevel::Error,
            message: $this->messageOf($exception),
            // `occurred_at` es el momento en que se informa: una excepcion no
            // lleva marca de tiempo y el sink no puede inventarsela. Es el unico
            // reloj de esta clase y esta en infraestructura, no en el dominio.
            occurredAt: new DateTimeImmutable('now'),
            appVersion: $this->appVersion,
            context: $frame->context,
            code: $this->codeOf($exception),
            exceptionClass: $exception::class,
            file: $this->relativeFile($exception->getFile()),
            line: $exception->getLine(),
            traceId: $this->traceId($request),
            deviceId: $this->publicIdOf($request, 'devices'),
            employeeUuid: $this->publicIdOf($request, 'employees'),
            module: $this->moduleOf($exception),
        );
    }

    /**
     * Sin marco de ejecucion, es una peticion HTTP: `api` con su ruta y su metodo.
     *
     * **Ruta y no URL**: el patron (`api/v1/employees/{employee}`) agrupa todas
     * las peticiones al mismo endpoint en una sola fila del historico, mientras
     * que la URL concreta crearia una por empleado. Cuando ninguna ruta casa
     * -un `404`, que ademas no llega hasta aqui- queda el camino pedido.
     */
    private function requestFrame(?Request $request): ExecutionFrame
    {
        if (! $request instanceof Request) {
            return new ExecutionFrame(ErrorSource::Api);
        }

        $route = $request->route();
        $path = '/'.ltrim($route instanceof Route ? $route->uri() : $request->path(), '/');

        return new ExecutionFrame(
            ErrorSource::Api,
            ['route' => $path, 'method' => $request->getMethod()],
            // Regla dura: el fichaje es el registro legal. Un fallo ahi se mira
            // ahora, no cuando alguien abra el panel (decision 3).
            critical: str_starts_with($path, ExecutionContext::CLOCKING_ROUTE_PREFIX),
        );
    }

    /**
     * `trace_id` de la traza en curso; si no hay SDK configurado -la mayoria de
     * las instalaciones- la del `traceparent` que envio el cliente, que es lo que
     * `PropagateTraceContext` activo al entrar.
     *
     * El parseo de la cabecera es {@see TraceparentHeader} y no una copia local:
     * este fichero tenia la tercera copia de la expresion regular del W3C y de la
     * normalizacion del identificador a ceros, y las tres no coincidian.
     */
    private function traceId(?Request $request): ?string
    {
        return SpanScope::currentTraceId()
            ?? TraceparentHeader::traceIdOf($request?->headers->get(TraceparentHeader::NAME));
    }

    /**
     * El identificador **publico** del portador del token, y solo si su tabla es
     * la que se pregunta.
     *
     * Se identifica por la tabla y no por la clase por lo mismo que
     * `Kiosk\Http\Support\KioskDevice`: `Product` no puede importar el modelo
     * `Device` de `Identity` ni el `Employee` de `Workforce` (doc 02 §1.6), y la
     * tabla es lo estable.
     */
    private function publicIdOf(?Request $request, string $table): ?string
    {
        $actor = $request?->user();

        if (! $actor instanceof Model || $actor->getTable() !== $table) {
            return null;
        }

        $uuid = $actor->getAttribute('uuid');

        return \is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /**
     * El modulo del monolito al que pertenece el fallo, para que el panel pueda
     * filtrar por el. Del espacio de nombres de la excepcion cuando es propia, y
     * del fichero cuando no lo es -un `TypeError` de PHP se lanza dentro del
     * modulo, aunque su clase no lo diga.
     */
    private function moduleOf(Throwable $exception): ?string
    {
        if (preg_match('/^App\\\\Modules\\\\([A-Za-z]+)\\\\/', $exception::class, $matches) === 1) {
            return strtolower($matches[1]);
        }

        $file = str_replace('\\', '/', $exception->getFile());

        return preg_match('#/app/Modules/([A-Za-z]+)/#', $file, $matches) === 1
            ? strtolower($matches[1])
            : null;
    }

    /**
     * El mensaje que se guarda, que para una consulta **no es `getMessage()`**.
     *
     * ## El fallo que esto arregla
     *
     * `QueryException::getMessage()` es el mensaje del driver mas
     * `(Connection: pgsql, SQL: …)` con los **parametros enlazados ya
     * sustituidos y sin comillas**:
     *
     * ```
     * … (Connection: pgsql, SQL: insert into "employees" (first_name, last_name,
     *   employee_code) values (Maria, Gonzalez Perez, EMP-0042))
     * ```
     *
     * El saneado del sink sustituye lo entrecomillado —que es donde una excepcion
     * normal interpola valores—, y aqui no hay comillas: el nombre, el codigo de
     * empleado y el hash de un PIN llegaban intactos a una tabla que viaja al
     * fabricante (regla dura 21, ADR-020). Lo encontro la revision ejecutandolo.
     *
     * ## Lo que se guarda en su lugar
     *
     * El **mensaje del driver** —que trae el `SQLSTATE`, la restriccion violada y
     * el `DETAIL` de PostgreSQL— y el **SQL con sus `?`**. Se pierde el valor
     * concreto y se conserva todo lo que sirve para arreglarlo: que restriccion
     * salto y en que consulta. El `DETAIL: Key (columna)=(valor)` que si trae el
     * driver lo neutraliza el saneado del sink, que es donde vive esa regla.
     *
     * Una `PDOException` suelta no tiene ese problema: su mensaje es el del
     * driver y ya viene sin bindings.
     */
    private function messageOf(Throwable $exception): string
    {
        if (! $exception instanceof QueryException) {
            return $exception->getMessage();
        }

        $driver = $exception->getPrevious()?->getMessage();

        if (! \is_string($driver) || trim($driver) === '') {
            // Sin excepcion anterior, el prefijo anterior a la parte que Laravel
            // compone: es donde empiezan los valores.
            $driver = explode(' (Connection: ', $exception->getMessage(), 2)[0];
        }

        return $this->withoutFailingRow($driver).self::SQL_SEPARATOR.$exception->getSql();
    }

    /**
     * Corta el `DETAIL: Failing row contains (…)` de PostgreSQL.
     *
     * Es el segundo sitio por el que una consulta fallida publica datos, y este
     * **vuelca la fila entera**: ante una violacion de `NOT NULL` o de un `CHECK`,
     * PostgreSQL lista todos los valores del `INSERT` -nombre, apellidos, codigo
     * de empleado, lo que fuera-. No es el `DETAIL: Key (columna)=(valor)` de una
     * clave duplicada, que si dice algo util y del que el saneado del sumidero
     * conserva la columna.
     *
     * Aqui no hay nada que conservar: el `SQLSTATE` y la restriccion, que van en
     * la primera linea del mensaje, ya dicen que fallo y donde. Se corta en el
     * origen, en vez de confiarlo al saneado, por la misma razon que no se usa
     * `getMessage()`: quien compone el mensaje es quien sabe que trozo lleva
     * valores.
     */
    private function withoutFailingRow(string $driverMessage): string
    {
        return (string) preg_replace(
            '/DETAIL:\s*Failing row contains .*/s',
            'DETAIL: Failing row contains [redacted]',
            $driverMessage,
        );
    }

    /**
     * El codigo que declare la excepcion, si declara alguno util. `SQLSTATE` de
     * PDO es el caso que justifica esto: agrupa por familia de fallo de base de
     * datos mucho mejor que el mensaje. El `0` de serie no dice nada y no viaja.
     */
    private function codeOf(Throwable $exception): ?string
    {
        $code = $exception->getCode();

        if (\is_string($code)) {
            return $code === '' ? null : $code;
        }

        return $code === 0 ? null : (string) $code;
    }

    /**
     * Relativa a la raiz del proyecto y con barras normales: un `file` absoluto
     * publica la ruta de instalacion del cliente en un fichero que viaja al
     * fabricante (ADR-020), y la barra invertida de Windows haria que el mismo
     * fallo tuviera dos huellas segun donde se ejecutara.
     */
    private function relativeFile(string $file): string
    {
        $normalized = str_replace('\\', '/', $file);
        $root = rtrim(str_replace('\\', '/', $this->basePath), '/').'/';

        return str_starts_with($normalized, $root)
            ? substr($normalized, \strlen($root))
            : $normalized;
    }
}
