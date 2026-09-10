<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\Exception\ShiftAlreadyOpen;
use App\Modules\Kiosk\Http\Support\KioskDevice;
use App\Modules\Product\Infrastructure\Capture\ExecutionContext;
use App\Modules\Shared\Application\Port\ErrorEventSink;
use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Product\InMemoryErrorEventSink;
use Tests\Support\Telemetry\RecordingTracer;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * LOS CUATRO ORIGENES DEL SERVIDOR, CON UN SOLO ENGANCHE (RF-PD-15, tarea 5.12,
 * decisiones 1, 2 y 3).
 *
 * ## Que se afirma
 *
 * Que un error que ocurre en una peticion, en un trabajo de cola, en una tarea
 * del planificador o en un comando **acaba en el historico con el origen
 * correcto**, y que un desenlace de negocio no acaba ahi. Las dos mitades
 * importan por igual:
 *
 * - Sin la primera, el panel de errores se queda mudo justo cuando hace falta y
 *   nadie lo nota, porque un panel vacio se lee como «no pasa nada».
 * - Sin la segunda, un `409` de «turno ya abierto» —el sistema funcionando— llena
 *   la tabla de ruido y entierra el fallo que si importa (§8.2.1).
 *
 * ## Y que capturar no silencia
 *
 * El informe normal sigue llegando a Monolog. `error_events` complementa el log
 * tecnico, no lo sustituye: Loki es opcional en la instalacion de un cliente,
 * pero el que lo tenga no debe perder nada por haber ganado esta tabla.
 *
 * ## Por que se dispara el evento y no se levanta un worker
 *
 * Los eventos de cola, planificador y consola son objetos reales del framework
 * con datos reales dentro (un `SyncJob`, un `Event` del planificador). Lo que
 * esta prueba tiene que fijar es **quien abre y quien cierra cada marco y en que
 * orden**, y eso se ve mejor disparando la secuencia exacta que levantando un
 * proceso de Horizon que ademas no seria reproducible.
 */

uses(RefreshDatabase::class);

/** La ruta de prueba que revienta, dentro del prefijo de la API. */
const RUTA_QUE_REVIENTA = '/api/v1/__capture/boom';

/** La misma, pero bajo el prefijo del fichaje: ahi todo es `critical`. */
const RUTA_DE_FICHAJE_QUE_REVIENTA = '/api/v1/scan/__capture/boom';

beforeEach(function (): void {
    $sink = new InMemoryErrorEventSink;

    // El mismo objeto bajo el puerto y bajo su propia clase: lo primero es lo que
    // usa el codigo, lo segundo lo que deja que `historico()` lo recupere con su
    // tipo de verdad en vez de comprobarlo a mano.
    app()->instance(ErrorEventSink::class, $sink);
    app()->instance(InMemoryErrorEventSink::class, $sink);

    // El contexto es un singleton por proceso —un worker vive horas—, asi que se
    // devuelve al estado inicial antes de cada prueba: un marco heredado de la
    // anterior daria el origen equivocado y ademas de forma intermitente.
    app()->make(ExecutionContext::class)->reset();

    Route::middleware('api')->group(function (): void {
        Route::get(RUTA_QUE_REVIENTA, static fn () => throw new RuntimeException('El adaptador no respondio'));
        Route::get(RUTA_DE_FICHAJE_QUE_REVIENTA, static fn () => throw new RuntimeException('El fichaje no se pudo escribir'));
        // Con el nombre que espera su traduccion en `bootstrap/app.php`: la
        // excepcion de dominio solo es un `409` en la ruta de los tramos, y lo
        // que aqui se reproduce es esa situacion completa —el sistema
        // funcionando— y no una excepcion suelta.
        Route::get('/api/v1/__capture/domain', static fn () => throw ShiftAlreadyOpen::forEmployee('0199a1f0-0000-7000-8000-000000000000'))
            ->name('attendance.shift-entries.__capture');
        // La otra mitad de la decision 1: una excepcion de dominio que NADIE
        // traduce. Vive en `Attendance\Domain\Exception`, o sea en la carpeta que
        // la primera version excluia entera, y produce un `500` de verdad.
        Route::get('/api/v1/__capture/sin-traduccion', static fn () => throw InstantIsNotUtc::forField(
            'occurred_at',
            new DateTimeImmutable('2026-09-09 08:00:00', new DateTimeZone('Europe/Madrid')),
        ));
        Route::get('/api/v1/__capture/not-found', static fn () => abort(404));
        Route::get('/api/v1/__capture/forbidden', static fn () => abort(403));
        Route::get('/api/v1/__capture/invalid', static fn () => throw ValidationException::withMessages(['campo' => 'mal']));
    });
});

/**
 * El doble del historico enlazado en el contenedor para esta prueba.
 */
function historico(): InMemoryErrorEventSink
{
    return app()->make(InMemoryErrorEventSink::class);
}

/**
 * Informa una excepcion como lo hace el framework, sin peticion HTTP de por
 * medio: es lo que ocurre en un worker, en el planificador y en la consola.
 */
function informar(Throwable $exception): void
{
    app()->make(ExceptionHandler::class)->report($exception);
}

/**
 * Un `INSERT` que va a fallar, **dentro de un punto de guardado**, y su
 * excepcion informada como lo hace el framework.
 *
 * El punto de guardado no es ceremonia: PostgreSQL aborta la transaccion entera
 * en cuanto una sentencia falla, y la prueba corre dentro de la de
 * `RefreshDatabase`. Sin el, cualquier consulta posterior de la misma prueba
 * moriria con «current transaction is aborted».
 *
 * @param  array<string, mixed>  $row
 */
function consultaQueFalla(array $row): void
{
    try {
        DB::transaction(static function () use ($row): void {
            DB::table('employees')->insert($row);
        });
    } catch (QueryException $exception) {
        informar($exception);
    }
}

/**
 * Un trabajo de cola de verdad, con su nombre y su cola, sin levantar un worker.
 *
 * `SyncJob` devuelve `'sync'` en `getQueue()` pase lo que pase —es una cola que
 * no existe—, y lo que esta prueba tiene que fijar es que la cola REAL llega al
 * historico: sin ella, «un trabajo esta fallando» no dice cual de las colas del
 * cliente esta parada. De ahi la subclase.
 */
function trabajoDeCola(string $name = 'App\\Jobs\\RebuildDailyTotals'): SyncJob
{
    $payload = (string) json_encode([
        'displayName' => $name,
        'job' => $name.'@handle',
        'data' => [],
        'attempts' => 1,
    ]);

    return new class(app(), $payload, 'redis', 'reconciliation') extends SyncJob
    {
        public function getQueue(): string
        {
            return 'reconciliation';
        }
    };
}

it('capta un fallo de la API con su ruta, su metodo y su traza', function (): void {
    $traceId = 'e1f2a3b4c5d60718293a4b5c6d7e8f90';

    Api::guest()
        ->withHeaders(['traceparent' => '00-'.$traceId.'-00f067aa0ba902b7-01'])
        ->get(RUTA_QUE_REVIENTA)
        ->assertStatus(500);

    $error = historico()->only();

    expect($error->source)->toBe(ErrorSource::Api)
        ->and($error->level)->toBe(ErrorLevel::Error)
        ->and($error->exceptionClass)->toBe(RuntimeException::class)
        // El PATRON de la ruta y no la URL: agrupa todas las peticiones al mismo
        // endpoint en una fila en vez de una por identificador.
        ->and($error->context['route'] ?? null)->toBe(RUTA_QUE_REVIENTA)
        ->and($error->context['method'] ?? null)->toBe('GET')
        // La correlacion con el log tecnico cuando el cliente si tenga Loki.
        ->and($error->traceId)->toBe($traceId)
        // Relativa a la raiz: un `file` absoluto publica el arbol del servidor
        // del cliente en un fichero que viaja al fabricante (ADR-020).
        ->and($error->file)->not->toStartWith('/')
        ->and($error->message)->toBe('El adaptador no respondio');
})->group('RF-PD-15');

it('sin SDK y sin traceparent, no inventa una traza', function (): void {
    /*
     * El estado de serie y el de la mayoria de las instalaciones: sin destino
     * OTLP no hay span y `Globals` devuelve el proveedor inerte, cuyo `trace_id`
     * son treinta y dos ceros. Escribir eso seria peor que no escribir nada
     * —parece un identificador y nadie lo buscaria dos veces—, asi que la columna
     * queda nula.
     */
    Api::guest()->get(RUTA_QUE_REVIENTA)->assertStatus(500);

    expect(historico()->only()->traceId)->toBeNull();
})->group('RF-PD-15');

it('con SDK y sin traceparent, fecha el error con la traza del span de servidor', function (): void {
    /*
     * **Y esto es lo correcto, no un caso residual** (decision 6 de la ficha 3.1).
     *
     * `PropagateTraceContext` abre un span `SERVER` por peticion. Cuando el
     * cliente no envia `traceparent` —una llamada desde `curl`, una sonda, un
     * navegador sin la cabecera— ese span es la RAIZ de una traza nueva, que es
     * exactamente la traza que estara en Tempo con las consultas SQL de la
     * peticion que reventó. Fechar `error_events.trace_id` con ella es lo que
     * convierte una fila del panel de errores en un enlace a lo que pasó.
     *
     * Lo que la prueba de arriba fija es que ese identificador **existe de
     * verdad**: sin SDK no se inventa uno.
     */
    RecordingTracer::around(function (RecordingTracer $tracer): void {
        Api::guest()->get(RUTA_QUE_REVIENTA)->assertStatus(500);

        $servidor = array_values(array_filter(
            $tracer->finishedSpans(),
            static fn (ImmutableSpan $span): bool => $span->getKind() === SpanKind::KIND_SERVER,
        ));

        expect($servidor)->not->toBeEmpty();

        expect(historico()->only()->traceId)->toBe($servidor[0]->getContext()->getTraceId());
    });
})->group('RF-PD-15');

it('marca como critico cualquier fallo en las rutas de fichaje', function (): void {
    // Decision 3: el fichaje es el registro legal. Un fallo ahi se mira ahora, no
    // cuando alguien abra el panel.
    Api::guest()->get(RUTA_DE_FICHAJE_QUE_REVIENTA)->assertStatus(500);

    expect(historico()->only()->level)->toBe(ErrorLevel::Critical);
})->group('RF-PD-15');

it('guarda una excepcion de dominio que nadie traduce, porque es un 500', function (): void {
    /*
     * **El fallo que encontro la revision.** La primera version descartaba por el
     * espacio de nombres: todo lo de `Domain\Exception` y `Application\Exception`
     * quedaba fuera. Pero 50 de las 96 excepciones de esos espacios NO tienen
     * `render` en `bootstrap/app.php` —esta es una— y producen un `500`. El panel
     * de errores del IT del cliente no las veia, y el sintoma era una tabla
     * limpia, que se lee como «no pasa nada».
     *
     * La regla nueva no mira la carpeta: pregunta al manejador que estado
     * responderia. `500`, luego es un error.
     */
    Api::guest()->get('/api/v1/__capture/sin-traduccion')->assertStatus(500);

    $error = historico()->only();

    expect($error->source)->toBe(ErrorSource::Api)
        // Fuera de las rutas de fichaje, `error`: hay que mirarlo, no despertar a
        // nadie (decision 3).
        ->and($error->level)->toBe(ErrorLevel::Error)
        ->and($error->exceptionClass)->toBe(InstantIsNotUtc::class)
        ->and($error->module)->toBe('attendance')
        ->and($error->context['route'] ?? null)->toBe('/api/v1/__capture/sin-traduccion');
})->group('RF-PD-15');

it('no cambia la respuesta por preguntarle al manejador que respondera', function (): void {
    /*
     * La regla nueva **renderiza dos veces**: una para decidir si es un error y
     * otra para responder. Eso solo es aceptable si `render()` no tiene efectos
     * -no informa, no audita, no consume nada-, y esto lo comprueba en lugar de
     * suponerlo: la respuesta que ve el cliente tiene que ser identica con la
     * captacion encendida y con el puerto desenchufado.
     */
    $conCaptacion = Api::guest()->get('/api/v1/__capture/domain');

    app()->make(ExecutionContext::class)->reset();
    historico()->forget();

    $sinCaptacion = Api::guest()->get('/api/v1/__capture/domain');

    expect($conCaptacion->getStatusCode())->toBe($sinCaptacion->getStatusCode())
        ->and($conCaptacion->getContent())->toBe($sinCaptacion->getContent())
        ->and($conCaptacion->headers->get('Content-Type'))->toBe($sinCaptacion->headers->get('Content-Type'));
})->group('RF-PD-15');

it('no guarda los desenlaces de negocio ni los rechazos del framework', function (string $uri, int $status): void {
    // Decision 1. Un `409` de «turno ya abierto» ES EL SISTEMA FUNCIONANDO, y un
    // `404`, un `403` o un `422` son la peticion, no la instalacion. Si entraran,
    // la tabla que el IT del cliente mira estaria llena de ruido.
    Api::guest()->get($uri)->assertStatus($status);

    expect(historico()->isEmpty())->toBeTrue();
})->with([
    'una excepcion de dominio' => ['/api/v1/__capture/domain', 409],
    'un 404' => ['/api/v1/__capture/not-found', 404],
    'un 403' => ['/api/v1/__capture/forbidden', 403],
    'un 422 de validacion' => ['/api/v1/__capture/invalid', 422],
])->group('RF-PD-15');

it('no guarda los parametros enlazados de una consulta que falla', function (): void {
    /*
     * **EL FALLO MAS GRAVE QUE ENCONTRO LA REVISION** (regla dura 21, decision 5).
     *
     * `QueryException::getMessage()` trae el SQL con los valores ya sustituidos y
     * **sin comillas**: `values (Maria, Gonzalez Perez, EMP-0042)`. El saneado del
     * sumidero sustituye lo entrecomillado —que es donde una excepcion normal
     * interpola—, asi que estos pasaban enteros a una tabla que viaja al
     * fabricante dentro del paquete de diagnostico. Con el hash de un PIN en el
     * `INSERT`, tambien ese.
     *
     * El enganche compone el mensaje del driver mas el SQL **con sus `?`**: se
     * pierde el valor y se conserva todo lo que sirve para arreglarlo.
     */
    $site = WorkforceFixtures::site('Hotel de la consulta que falla');
    WorkforceFixtures::employee($site, employeeCode: 'EMP-0042');

    // El `INSERT` que choca con el UNIQUE de `employee_code`, con nombre y codigo
    // dentro: el caso realista de un alta duplicada.
    consultaQueFalla([
        'uuid' => (string) Str::uuid7(),
        'site_id' => $site,
        'first_name' => 'Maria',
        'last_name' => 'Gonzalez Perez',
        'employee_code' => 'EMP-0042',
        'status' => 'active',
        'hired_at' => '2026-01-01',
        'locale' => 'es',
    ]);

    $error = historico()->only();

    // Laravel afina la clase: una clave duplicada llega como
    // `UniqueConstraintViolationException`, que ES una `QueryException`. La
    // composicion del mensaje se decide por `instanceof`, no por el nombre, y por
    // eso cubre tambien a las subclases que el framework anada.
    expect($error->exceptionClass)->toBe(UniqueConstraintViolationException::class)
        ->and(is_a($error->exceptionClass ?? '', QueryException::class, true))->toBeTrue()
        ->and($error->message)->not->toContain('Maria')
        ->and($error->message)->not->toContain('Gonzalez Perez')
        // Lo que si se conserva, que es lo que hace diagnosticable el fallo: que
        // restriccion salto y en que consulta.
        ->and($error->message)->toContain('insert into')
        ->and($error->message)->toContain('?')
        ->and($error->message)->toContain('employees_employee_code_unique');

    // El `DETAIL: Key (employee_code)=(EMP-0042)` que si trae el driver lo
    // neutraliza el saneado del sumidero, que es donde vive esa regla y donde lo
    // comprueba `ErrorEventsHaveNoPersonalDataTest` contra la tabla de verdad.
})->group('RF-PD-15', 'RL-19');

it('no vuelca la fila entera cuando una consulta viola un NOT NULL', function (): void {
    /*
     * El segundo sitio por el que una consulta publica datos, y peor que el
     * primero: ante una violacion de `NOT NULL` o de un `CHECK`, PostgreSQL
     * responde `DETAIL: Failing row contains (1, null, …, Maria, Gonzalez Perez,
     * EMP-0042, …)` — **la fila entera**, columna a columna. No lo cubre la red
     * de `DETAIL: Key (…)=(…)` del saneado, porque no tiene esa forma.
     */
    consultaQueFalla([
        'first_name' => 'Maria',
        'last_name' => 'Gonzalez Perez',
        'employee_code' => 'EMP-0042',
    ]);

    $error = historico()->only();

    expect($error->message)->not->toContain('Maria')
        ->and($error->message)->not->toContain('Gonzalez Perez')
        ->and($error->message)->not->toContain('EMP-0042')
        ->and($error->message)->toContain('[redacted]')
        // Y sigue diciendo que fallo: la columna y la restriccion van antes del
        // `DETAIL`, no dentro.
        ->and($error->message)->toContain('not-null constraint');
})->group('RF-PD-15', 'RL-19');

it('sigue informando a Monolog de lo que capta', function (): void {
    // Capturar NO silencia (§8.2.1): el `reportable` no devuelve `false`. Quien
    // tenga Loki no pierde nada por haber ganado esta tabla; quien no lo tenga
    // gana la tabla.
    $registrado = [];

    Log::listen(function (MessageLogged $message) use (&$registrado): void {
        $registrado[] = $message->message;
    });

    Api::guest()->get(RUTA_QUE_REVIENTA)->assertStatus(500);

    expect($registrado)->toContain('El adaptador no respondio')
        ->and(historico()->reports)->toHaveCount(1);
})->group('RF-PD-15');

it('capta un fallo de un trabajo de cola con su nombre y su cola', function (): void {
    // Todavia le quedan intentos: es `error`, no `critical`. Alguien lo va a
    // reintentar y puede que salga.
    event(new JobProcessing('sync', trabajoDeCola()));

    informar(new RuntimeException('La conexion se cayo a mitad'));

    $error = historico()->only();

    expect($error->source)->toBe(ErrorSource::Worker)
        ->and($error->level)->toBe(ErrorLevel::Error)
        ->and($error->context['job'] ?? null)->toBe('App\\Jobs\\RebuildDailyTotals')
        ->and($error->context['queue'] ?? null)->toBe('reconciliation')
        ->and($error->context['attempts'] ?? null)->toBe(1);
})->group('RF-PD-15');

it('marca como critico el trabajo que agota sus intentos', function (): void {
    // `JobFailed` significa que nadie lo esta mirando y el resultado ya no
    // llegara (decision 3). Se despacha ANTES del informe: `Worker::runJob()`
    // atrapa, reporta y sigue.
    $trabajo = trabajoDeCola();
    $causa = new RuntimeException('La conexion se cayo a mitad');

    event(new JobProcessing('sync', $trabajo));
    event(new JobFailed('sync', $trabajo, $causa));

    informar($causa);

    expect(historico()->only()->level)->toBe(ErrorLevel::Critical);
})->group('RF-PD-15');

it('capta un fallo del planificador como critico y con el nombre de la tarea', function (): void {
    // Toda tarea programada es `critical`: la reconciliacion nocturna o la purga
    // que fallan en silencio son justo lo que la alerta del doc 01 §9.3 quiere
    // sacar a la luz.
    $tarea = app()->make(Schedule::class)->command('product:errors:prune');
    $causa = new RuntimeException('La purga no pudo abrir la conexion');

    event(new ScheduledTaskStarting($tarea));
    // `ScheduleRunCommand` despacha `Finished` ANTES de lanzar por codigo de
    // salida distinto de cero: sin que `Failed` vuelva a abrir el marco, el error
    // se contaria como consola.
    event(new ScheduledTaskFinished($tarea, 0.42));
    event(new ScheduledTaskFailed($tarea, $causa));

    informar($causa);

    $error = historico()->only();

    expect($error->source)->toBe(ErrorSource::Scheduler)
        ->and($error->level)->toBe(ErrorLevel::Critical)
        // El nombre de la tarea, no la linea de ordenes con el binario de PHP.
        ->and($error->context['command'] ?? null)->toBe('product:errors:prune');
})->group('RF-PD-15');

it('capta un fallo de un comando de consola', function (): void {
    event(new CommandStarting('employees:import', new ArrayInput([]), new NullOutput));

    informar(new RuntimeException('El fichero no se pudo leer'));

    $error = historico()->only();

    expect($error->source)->toBe(ErrorSource::Console)
        ->and($error->level)->toBe(ErrorLevel::Error)
        ->and($error->context['command'] ?? null)->toBe('employees:import');
})->group('RF-PD-15');

it('atribuye al planificador lo que falla dentro de schedule:run', function (): void {
    // `schedule:run` ES un comando que lanza otros. Sin esta precedencia, todo
    // fallo del planificador se contaria como consola y la alerta de «tarea
    // programada que falla en silencio» no distinguiria nada.
    $tarea = app()->make(Schedule::class)->command('compliance:apply-retention');

    event(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput));
    event(new ScheduledTaskStarting($tarea));

    informar(new RuntimeException('La reconciliacion no termino'));

    expect(historico()->only()->source)->toBe(ErrorSource::Scheduler);
})->group('RF-PD-15');

it('deduce el modulo del monolito por el fichero donde se rompio', function (): void {
    // Para que el panel pueda filtrar por modulo sin que nadie lo escriba a mano.
    // Un `RuntimeException` no dice de donde viene: lo dice el FICHERO. Este sale
    // de `Kiosk/Http/Support/KioskDevice`, que es el caso real —una comprobacion
    // de programa dentro de un modulo, lanzada con una clase de PHP.
    try {
        KioskDevice::of(Request::create('/'));
    } catch (Throwable $exception) {
        informar($exception);
    }

    expect(historico()->only()->module)->toBe('kiosk');

    historico()->forget();

    // Y lo que se rompe fuera de los modulos no se atribuye a ninguno, en vez de
    // caer en el primero por descarte.
    informar(new RuntimeException('Fuera de todo modulo'));

    expect(historico()->only()->module)->toBeNull();
})->group('RF-PD-15');
