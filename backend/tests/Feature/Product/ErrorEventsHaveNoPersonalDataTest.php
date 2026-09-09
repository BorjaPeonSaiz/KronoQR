<?php

declare(strict_types=1);

use App\Modules\Product\Infrastructure\Capture\ExecutionContext;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LA INSPECCION MANUAL DE LA TABLA, AUTOMATIZADA** (RF-PD-15, RL-19, regla
 * dura 21; decision 14 de la ficha 5.12).
 *
 * La ficha exige mirar `error_events` despues de un dia de uso con la semilla
 * realista y comprobar que no hay «ni un nombre, ni un correo, ni una hora de
 * fichaje». Esa inspeccion sigue en la DoD porque un par de ojos ve cosas que una
 * prueba no; pero una comprobacion que solo existe como costumbre no protege
 * nada el dia que nadie la haga.
 *
 * Aqui se provoca **un error por cada uno de los siete origenes** con mensajes y
 * contextos deliberadamente cargados de datos personales, y se afirma que
 * **ninguna columna de ninguna fila** los contiene.
 *
 * ## Por que importa tanto esta tabla en concreto
 *
 * `error_events` viaja al fabricante dentro del paquete de diagnostico
 * (ADR-020, §11.6.6). Es una de las poquisimas cosas que salen de la instalacion
 * del cliente. Si lleva PII, se ha filtrado — y se ha filtrado con el
 * consentimiento de nadie.
 *
 * ## Donde se planta cada dato, y por que ahi
 *
 * - **En el mensaje, entre comillas.** Es donde las excepciones interpolan
 *   valores («Employee 'Maria Gonzalez' ...»), y un nombre de persona no se
 *   detecta de ninguna otra forma: no hay patron que distinga un apellido de un
 *   sustantivo. Por eso el saneado sustituye todo lo entrecomillado (decision 5).
 * - **En claves de contexto que no estan en la lista de permitidos** (`name`,
 *   `email`, `dni`, `phone`): tienen que desaparecer enteras, valor incluido.
 * - **En una clave que si esta permitida** (`reason`), con el nombre entre
 *   comillas y una hora de fichaje: ahi lo que se comprueba es que el valor
 *   tambien pasa por el saneado y no solo la llave.
 */

/*
 * `RefreshDatabase` basta **porque el `beforeEach` global de `tests/Pest.php`
 * comparte la transaccion de la prueba con la conexion `error_events`**
 * ({@see \Tests\Support\Product\ErrorHistoryConnection::shareTestTransaction()}).
 *
 * Sin el no bastaria, y merece la pena saber por que: el sumidero escribe por
 * una conexion propia (decision 6) para no depender de la transaccion que acaba
 * de fallar, asi que sus filas se confirman y **sobreviven** al `RefreshDatabase`
 * de la prueba que las escribio. Las dos pruebas de aqui cuentan filas, de modo
 * que sin ese enganche estarian contando tambien las de la anterior.
 */
uses(RefreshDatabase::class);

/** Nombre completo, como lo interpolaria una excepcion de dominio. */
const NOMBRE = 'Maria Gonzalez Perez';

const CORREO = 'maria.gonzalez@hotelplaya.es';

const DOCUMENTO = '12345678Z';

/** Hora de fichaje: el dato de jornada que el §8.2.1 prohibe expresamente. */
const HORA_DE_FICHAJE = '06:00';

const TELEFONO = '+34612345678';

/**
 * El codigo de empleado. No es un dato «personal» en el sentido corriente, pero
 * identifica a una persona dentro de la instalacion y es lo que PostgreSQL
 * publica en el `DETAIL` de una clave duplicada.
 */
const CODIGO_DE_EMPLEADO = 'EMP-0042';

/** Un payload de tarjeta: vale tanto como una contrasena (regla dura 10). */
const PAYLOAD_QR = 'FH1.a3.QUJDREVGR0hJSktM.c2lnbmF0dXJl';

/**
 * Lo que no puede aparecer en ninguna columna de ninguna fila.
 *
 * @return list<string>
 */
function datosPersonalesPlantados(): array
{
    return [NOMBRE, CORREO, DOCUMENTO, HORA_DE_FICHAJE, TELEFONO, PAYLOAD_QR];
}

/**
 * Un mensaje de excepcion con los seis datos dentro, escrito como lo escribiria
 * alguien que no esta pensando en la regla dura 21 — que es el caso realista.
 */
function mensajeConDatosPersonales(): string
{
    return "No se pudo fichar a '".NOMBRE."' (".DOCUMENTO.', '.CORREO.', tel. '.TELEFONO
        .') a las '.HORA_DE_FICHAJE.' con la tarjeta '.PAYLOAD_QR;
}

/**
 * Contexto con PII en claves prohibidas y en una permitida.
 *
 * @return array<string, string>
 */
function contextoConDatosPersonales(): array
{
    return [
        'name' => NOMBRE,
        'email' => CORREO,
        'dni' => DOCUMENTO,
        'phone' => TELEFONO,
        'reason' => "La empleada '".NOMBRE."' ficho a las ".HORA_DE_FICHAJE,
    ];
}

/**
 * Un error de cliente con la forma del contrato y todo el veneno dentro.
 *
 * @return array<string, mixed>
 */
function errorDeClienteConDatosPersonales(string $code): array
{
    return [
        'code' => $code,
        'occurred_at' => '2026-09-09T05:58:31Z',
        'app_version' => '2.2.0',
        'context' => [...contextoConDatosPersonales(), 'message' => mensajeConDatosPersonales()],
    ];
}

/**
 * Todo lo que hay en la tabla, serializado, para poder buscar en cualquier
 * columna sin enumerarlas: enumerarlas dejaria fuera la que alguien anada manana,
 * que es justo por donde se escaparia el dato.
 */
function todoElHistorico(): string
{
    return (string) json_encode(DB::table('error_events')->get()->toArray());
}

function informarConDatosPersonales(): void
{
    informarLaExcepcion(new RuntimeException(mensajeConDatosPersonales()));
}

function informarLaExcepcion(Throwable $exception): void
{
    app()->make(ExceptionHandler::class)->report($exception);
}

/**
 * Ejecuta un `INSERT` que va a fallar **dentro de un punto de guardado** y
 * reporta lo que salga.
 *
 * El punto de guardado no es ceremonia: PostgreSQL aborta la transaccion entera
 * en cuanto una sentencia falla, y la prueba corre dentro de la transaccion de
 * `RefreshDatabase`. Sin el, la consulta que despues lee `error_events` moriria
 * con «current transaction is aborted» y el fallo pareceria del historico.
 *
 * @param  array<string, mixed>  $row
 */
function altaQueChoca(array $row): void
{
    try {
        DB::transaction(static function () use ($row): void {
            DB::table('employees')->insert($row);
        });
    } catch (QueryException $exception) {
        informarLaExcepcion($exception);
    }
}

it('no guarda ni un dato personal por ninguno de los siete origenes', function (): void {
    app()->make(ExecutionContext::class)->reset();

    // 1. `api` — una peticion que revienta con el nombre de una persona dentro.
    Route::middleware('api')->get(
        '/api/v1/__pii/boom',
        static fn () => throw new RuntimeException(mensajeConDatosPersonales()),
    );

    Api::guest()->get('/api/v1/__pii/boom')->assertStatus(500);

    // 2. `worker` — el trabajo de cola que reconcilia jornadas.
    $trabajo = new SyncJob(app(), (string) json_encode([
        'displayName' => 'App\\Jobs\\RebuildDailyTotals',
        'job' => 'App\\Jobs\\RebuildDailyTotals@handle',
        'data' => [],
    ]), 'sync', 'reconciliation');

    event(new JobProcessing('sync', $trabajo));
    informarConDatosPersonales();

    // Se cierra el marco del trabajo antes de abrir el siguiente: `worker` tiene
    // precedencia sobre `scheduler`, asi que sin esto el error de la tarea
    // programada se contaria como de cola. Es la misma secuencia que en un
    // proceso de verdad, donde cada origen vive en su propio proceso.
    app()->make(ExecutionContext::class)->reset();

    // 3. `scheduler` — la tarea nocturna.
    event(new ScheduledTaskStarting(app()->make(Schedule::class)->command('compliance:apply-retention')));
    informarConDatosPersonales();

    app()->make(ExecutionContext::class)->reset();

    // 4. `console` — un comando lanzado a mano.
    event(new CommandStarting('employees:import', new ArrayInput([]), new NullOutput));
    informarConDatosPersonales();

    app()->make(ExecutionContext::class)->reset();

    // 5. `kiosk` — dentro del latido, que es su unico canal (decision 7).
    $quiosco = AttendanceFixtures::scenario();

    Api::as($quiosco['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '2.2.0',
        'pending_queue_size' => 0,
        'client_errors' => [errorDeClienteConDatosPersonales('kiosk.camera.stream_lost')],
    ])->assertOk();

    // 6. `admin` — el panel, con su sesion de gestion.
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->post('/api/v1/client-errors', ['errors' => [errorDeClienteConDatosPersonales('web.unhandled_rejection')]])
        ->assertStatus(202);

    // 7. `portal` — el empleado consultando su propio registro.
    Api::as(PortalLogins::open($quiosco['employee']))
        ->post('/api/v1/client-errors', ['errors' => [errorDeClienteConDatosPersonales('web.vue_error')]])
        ->assertStatus(202);

    $historico = todoElHistorico();

    // Los siete estan, que es la mitad que hace significativa a la otra: una
    // tabla vacia tambien pasaria la busqueda.
    expect(DB::table('error_events')->pluck('source')->sort()->values()->all())
        ->toBe(['admin', 'api', 'console', 'kiosk', 'portal', 'scheduler', 'worker']);

    foreach (datosPersonalesPlantados() as $dato) {
        expect($historico)->not->toContain($dato);
    }
})->group('RF-PD-15', 'RL-19');

it('no guarda ni un dato personal cuando quien falla es la base de datos', function (): void {
    /*
     * **EL OCTAVO ORIGEN, QUE NO ES UN ORIGEN SINO UNA FORMA DE MENSAJE.**
     *
     * Una `QueryException` es el error de servidor mas comun de todos y el que
     * mas datos lleva encima: Laravel compone su mensaje interpolando los
     * parametros enlazados **sin comillas**, y PostgreSQL anade su propio
     * `DETAIL` con el valor que choco o con la fila entera. Tres caminos
     * distintos por los que un nombre, un codigo de empleado o el hash de un PIN
     * acaban en la tabla que viaja al fabricante.
     *
     * Se prueban los dos `DETAIL` de PostgreSQL, que tienen forma distinta:
     *
     * - clave duplicada -> `DETAIL: Key (employee_code)=(EMP-0042) already exists.`
     * - `NOT NULL` -> `DETAIL: Failing row contains (1, null, …, Maria, …).`
     */
    app()->make(ExecutionContext::class)->reset();

    $site = WorkforceFixtures::site('Hotel del alta duplicada');
    WorkforceFixtures::employee($site, employeeCode: CODIGO_DE_EMPLEADO);

    // Clave duplicada: el alta de alguien que ya estaba.
    altaQueChoca([
        'uuid' => (string) Str::uuid7(),
        'site_id' => $site,
        'first_name' => 'Maria',
        'last_name' => 'Gonzalez Perez',
        'employee_code' => CODIGO_DE_EMPLEADO,
        'status' => 'active',
        'hired_at' => '2026-01-01',
        'locale' => 'es',
    ]);

    // `NOT NULL`: una importacion a medio mapear, que es como ocurre de verdad.
    altaQueChoca([
        'first_name' => 'Maria',
        'last_name' => 'Gonzalez Perez',
        'employee_code' => CODIGO_DE_EMPLEADO,
    ]);

    $historico = todoElHistorico();

    // Las dos filas estan: una tabla vacia tambien pasaria la busqueda.
    expect(DB::table('error_events')->count())->toBe(2);

    foreach ([NOMBRE, 'Maria', 'Gonzalez Perez', CODIGO_DE_EMPLEADO] as $dato) {
        expect($historico)->not->toContain($dato);
    }

    /*
     * Y sigue sirviendo para arreglarlo. Lo que queda tras el saneado del
     * sumidero es el `SQLSTATE`, la clase de violacion y la forma de la consulta
     * con sus marcadores:
     *
     *   SQLSTATE[23505]: Unique violation: … violates unique constraint '…'
     *   DETAIL: Key ('…')=('…') already exists. | sql: insert into '…' (…) values (?, ?, …)
     *
     * **El nombre de la restriccion y el de las columnas NO sobreviven**, porque
     * PostgreSQL los entrecomilla y el saneado sustituye todo lo entrecomillado
     * (decision 5): es la regla que atrapa los nombres de persona y no distingue
     * un apellido de un identificador de esquema. Se pierde diagnostico y se gana
     * la garantia; queda anotado para A por si la lista de permitidos del
     * saneado puede recuperar los identificadores SQL sin abrir la puerta.
     */
    expect($historico)->toContain('SQLSTATE[23505]')
        ->and($historico)->toContain('Unique violation')
        ->and($historico)->toContain('SQLSTATE[23502]')
        ->and($historico)->toContain('insert into')
        ->and($historico)->toContain('values (?');
})->group('RF-PD-15', 'RL-19');
