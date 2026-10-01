<?php

declare(strict_types=1);

use App\Modules\Product\Infrastructure\Capture\ExecutionContext;
use App\Support\Observability\Logging\CorrelationOnlyExtra;
use App\Support\Observability\Logging\LokiHandler;
use App\Support\Observability\Logging\LokiLogChannel;
use App\Support\Observability\Logging\LokiTransport;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Observability\RecordingLokiTransport;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LO QUE SALE POR EL CABLE HACIA LOKI NO LLEVA EL NOMBRE DE NADIE** (RL-08,
 * RF-PD-15, regla dura 21, doc 01 §9.4, decision 8 de la ficha 3.1).
 *
 * ## El hueco que cierra, teniendo ya `LogExtraAllowlistTest`
 *
 * Aquel fichero es unitario y afirma sobre el `LogRecord` que devuelve
 * {@see CorrelationOnlyExtra}: comprueba que **ese processor poda**. Lo que no
 * puede comprobar es que el processor **este montado en el canal que envia**, ni
 * que lo este en el orden correcto, y las dos cosas dependen de piezas que la
 * suite unitaria no toca: el `tap` que declara `config/logging.php`, el
 * `ContextLogProcessor` que Laravel empuja *despues* de los taps —y que por
 * tanto corre *antes*— y el `JsonFormatter` del handler, que serializa `extra`
 * tal cual.
 *
 * Un `AddCorrelation` que dejara de empujar el podador, un `tap` retirado del
 * canal `loki` en el `config`, o un cambio de orden en la pila de Monolog,
 * dejarian la prueba unitaria en verde y el nombre de una persona viajando a
 * Loki, donde se quedaria los 90 dias de la retencion. Lo unico que distingue
 * los dos casos es mirar **el cuerpo del `POST`**, que es lo que hace este
 * fichero.
 *
 * ## Por que dos pruebas: `loki` a solas y la pila
 *
 * Porque son dos cadenas de processors distintas, y el producto usa la segunda.
 * `LOG_CHANNEL=stack` de serie, y `createStackDriver()` de Laravel **concatena
 * los processors de cada canal de la pila** y empuja ademas su propio
 * `ContextLogProcessor` al frente. El resultado es que el `Context` entero se
 * vuelca en `extra` mas de una vez, intercalado con los podados. Que la cadena
 * resultante siga terminando en podar no es evidente leyendola: depende del
 * orden en que Laravel concatena. Con el canal suelto solo se comprobaria el
 * caso que casi ninguna instalacion usa.
 *
 * ## Con un transporte de pruebas, no con un Loki de verdad
 *
 * Lo que hay que leer es el cuerpo enviado, y para eso hace falta capturarlo.
 * La otra mitad —que un Loki caido no retrase un fichaje— necesita justo lo
 * contrario, un socket de verdad, y tiene su propio fichero
 * (`LoggingDoesNotBlockClockingTest`).
 */

uses(RefreshDatabase::class);

/** Nadie escucha ahi y da igual: el transporte de pruebas no abre la conexion. */
const LOKI_QUE_GRABA_LO_ENVIADO = 'http://loki-de-pruebas:3100';

/** El dato de la regla dura 21. Inventado: en una prueba no entra el de nadie. */
const NOMBRE_DEJADO_EN_EL_CONTEXT = 'Nombre Apellido';

const TRACE_ID_DE_LA_LINEA_ENVIADA = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

/**
 * Enciende el canal `loki` contra un transporte que graba lo que se le pasa.
 *
 * Se reconstruyen el handler y la factoria del canal porque los dos son
 * singletons y `config/logging.php` ya decidio la pila al arrancar: cambiar la
 * configuracion sin olvidar las instancias dejaria en pie el canal viejo —el que
 * la suite monta con `LOKI_URL` vacia, que es un `NullHandler`— y la prueba no
 * capturaria nada.
 *
 * @param  list<string>  $pila  Los canales de `stack`.
 */
function enciendeElCanalLoki(RecordingLokiTransport $transport, array $pila): void
{
    config([
        'logging.channels.loki.url' => LOKI_QUE_GRABA_LO_ENVIADO,
        'logging.channels.stack.channels' => $pila,
    ]);

    app()->instance(LokiTransport::class, $transport);
    app()->forgetInstance(LokiHandler::class);
    app()->forgetInstance(LokiLogChannel::class);
    Log::forgetChannel('loki');
    Log::forgetChannel('stack');
}

/**
 * El unico cuerpo que se ha enviado a Loki.
 *
 * Que sea **uno** forma parte de la afirmacion y no es una comodidad: dos
 * envios significarian un `POST` por linea, y ahi el canal deja de costar lo que
 * la decision 8 acepta pagar en el camino del fichaje.
 */
function cuerpoEnviadoALoki(RecordingLokiTransport $transport): string
{
    expect($transport->pushes)->toHaveCount(1);

    return $transport->pushes[0]['payload'];
}

afterEach(function (): void {
    config([
        'logging.channels.loki.url' => '',
        'logging.channels.stack.channels' => ['stderr'],
        'logging.channels.stderr.handler_with.stream' => 'php://stderr',
    ]);
    Log::forgetChannel('stderr');

    app()->forgetInstance(LokiHandler::class);
    app()->forgetInstance(LokiLogChannel::class);
    app()->forgetInstance(LokiTransport::class);
    Log::forgetChannel('loki');
    Log::forgetChannel('stack');
});

it('no manda a Loki el nombre que alguien dejo en el Context', function (): void {
    // Arrange: el canal encendido y el `Context` contaminado, como lo dejaria
    // cualquier punto del producto tres capas mas arriba de quien escribe la
    // linea.
    $transport = RecordingLokiTransport::accepting();
    enciendeElCanalLoki($transport, ['stderr', 'loki']);

    Context::add('trace_id', TRACE_ID_DE_LA_LINEA_ENVIADA);
    Context::add('employee_name', NOMBRE_DEJADO_EN_EL_CONTEXT);

    // Act: una linea por el canal y el vaciado que en produccion hace el
    // `terminating()` de la aplicacion.
    Log::channel('loki')->info('attendance.scan_processed');
    app(LokiHandler::class)->flush();

    // Assert: sobre el CUERPO del `POST`, que es lo que Loki recibe y guarda.
    $cuerpo = cuerpoEnviadoALoki($transport);

    expect($cuerpo)->not->toContain('employee_name')
        ->and($cuerpo)->not->toContain(NOMBRE_DEJADO_EN_EL_CONTEXT)
        // Y el identificador con el que se sigue una peticion por el log si
        // viaja: sin el, la copia consultable no sirve para lo que existe.
        ->and($cuerpo)->toContain(TRACE_ID_DE_LA_LINEA_ENVIADA);
})->group('RL-08', 'RF-PD-15');

it('tampoco lo manda por la pila completa, que es el canal que usa el producto', function (): void {
    // El mismo dato por el camino real: `LOG_CHANNEL=stack`, con `stderr` y
    // `loki` juntos. La cadena de processors que compone `createStackDriver()`
    // no es la del canal suelto — ver el docblock de arriba.
    $transport = RecordingLokiTransport::accepting();
    enciendeElCanalLoki($transport, ['stderr', 'loki']);

    Context::add('trace_id', TRACE_ID_DE_LA_LINEA_ENVIADA);
    Context::add('employee_name', NOMBRE_DEJADO_EN_EL_CONTEXT);

    Log::channel('stack')->info('attendance.scan_processed');
    app(LokiHandler::class)->flush();

    $cuerpo = cuerpoEnviadoALoki($transport);

    expect($cuerpo)->not->toContain('employee_name')
        ->and($cuerpo)->not->toContain(NOMBRE_DEJADO_EN_EL_CONTEXT)
        ->and($cuerpo)->toContain(TRACE_ID_DE_LA_LINEA_ENVIADA);
})->group('RL-08', 'RF-PD-15');

/*
 * ---------------------------------------------------------------------------
 * L1 Y PR12: LO QUE TRAE LA EXCEPCION, NO SOLO LO QUE HAY EN EL `Context`.
 *
 * Las dos pruebas de arriba fijan que `extra` se poda. Estas fijan la otra
 * fuga, la que encontro la verificacion de la 2.1.0 en el log del contenedor:
 * **el mensaje de una excepcion no controlada** —y su `context.exception`, que
 * el `JsonFormatter` serializa con el mensaje dentro— llevaba el
 * `employee_code` en claro. Se mira donde de verdad termina: los bytes que
 * escribe el canal `stderr`, el cuerpo del `POST` a Loki y la fila de
 * `error_events`. Un processor que sanea un `LogRecord` en una prueba unitaria
 * no dice nada de esos tres sitios si no esta montado, o si corre antes de que
 * alguien interpole el mensaje.
 * ---------------------------------------------------------------------------
 */

/** La forma canonica de `EmployeeCode::generate()`. Inventado. */
const LOKI_PII_CODIGO_DE_EMPLEADO = 'E7K2M9QX4B';

/** Con puntos, como lo teclea una persona; la forma compacta ya se cubria. */
const LOKI_PII_DNI = '12.345.678-Z';

const LOKI_PII_IBAN = 'ES91 2100 0418 4502 0005 1332';

/**
 * Lo que no puede aparecer, en todas sus formas: con y sin separadores. Un
 * saneado que quitara el IBAN agrupado y dejara las cifras sueltas no valdria.
 *
 * @return list<string>
 */
function lokiPiiProhibidos(): array
{
    return [
        LOKI_PII_CODIGO_DE_EMPLEADO,
        LOKI_PII_DNI,
        '12345678Z',
        '345.678',
        LOKI_PII_IBAN,
        '2100 0418',
        '21000418',
    ];
}

/**
 * Redirige el canal `stderr` a un fichero para leer los bytes que escribe, con
 * su formateador JSON de verdad.
 */
function lokiPiiStderrAFichero(): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'kq-stderr-');

    config(['logging.channels.stderr.handler_with.stream' => $path]);
    Log::forgetChannel('stderr');
    Log::forgetChannel('stack');

    return $path;
}

/**
 * Las lineas escritas en el fichero, cada una ya decodificada: si una no es
 * JSON, la prueba falla aqui, que es lo que fija que el formato no se rompe.
 *
 * @return list<array<string, mixed>>
 */
function lokiPiiLineasDe(string $path): array
{
    $lineas = array_values(array_filter(explode("\n", (string) file_get_contents($path)), static fn (string $l): bool => $l !== ''));

    return array_map(static function (string $linea): array {
        /** @var array<string, mixed> $decodificada */
        $decodificada = json_decode($linea, true, 512, JSON_THROW_ON_ERROR);

        return $decodificada;
    }, $lineas);
}

it('no escribe en stderr ni manda a Loki el codigo, el DNI ni el IBAN que trae una excepcion', function (): void {
    $stderr = lokiPiiStderrAFichero();
    $transport = RecordingLokiTransport::accepting();
    enciendeElCanalLoki($transport, ['stderr', 'loki']);

    Context::add('trace_id', TRACE_ID_DE_LA_LINEA_ENVIADA);

    // Ni entrecomillado ni dentro de un `SQL:`: la forma que el saneado
    // anterior dejaba pasar entera. Y en la excepcion PREVIA tambien, que el
    // formateador serializa como `previous`.
    $previa = new RuntimeException('employee_code='.LOKI_PII_CODIGO_DE_EMPLEADO.' sin tarjeta');
    $excepcion = new RuntimeException(
        'No se pudo abonar la nomina a '.LOKI_PII_IBAN.' de '.LOKI_PII_DNI.' ('.LOKI_PII_CODIGO_DE_EMPLEADO.')',
        0,
        $previa,
    );

    // Como lo escribe el manejador de excepciones de Laravel: el mensaje como
    // linea y la excepcion en el contexto. Mas un `{placeholder}` que
    // `PsrLogMessageProcessor` interpola: el saneado tiene que correr DESPUES.
    Log::channel('stack')->error($excepcion->getMessage(), ['exception' => $excepcion]);
    Log::channel('stack')->warning('fichaje de {codigo} rechazado', ['codigo' => LOKI_PII_CODIGO_DE_EMPLEADO]);
    app(LokiHandler::class)->flush();

    $cuerpo = cuerpoEnviadoALoki($transport);
    $lineas = lokiPiiLineasDe($stderr);
    $escrito = (string) file_get_contents($stderr);
    @unlink($stderr);

    foreach (lokiPiiProhibidos() as $prohibido) {
        expect($cuerpo)->not->toContain($prohibido)
            ->and($escrito)->not->toContain($prohibido);
    }

    // El formato no se rompe: dos lineas JSON, la excepcion sigue siendo un
    // objeto con su clase y su `previous`, y el `trace_id` sigue ahi.
    expect($lineas)->toHaveCount(2)
        ->and(data_get($lineas[0], 'context.exception.class'))->toBe(RuntimeException::class)
        ->and(data_get($lineas[0], 'context.exception.previous.class'))->toBe(RuntimeException::class)
        ->and(data_get($lineas[0], 'extra.trace_id'))->toBe(TRACE_ID_DE_LA_LINEA_ENVIADA)
        ->and($lineas[1]['message'] ?? null)->toBe('fichaje de [code] rechazado')
        ->and($cuerpo)->toContain(TRACE_ID_DE_LA_LINEA_ENVIADA);
})->group('RL-08', 'RF-PD-15', 'RL-19');

it('una excepcion no controlada en una peticion no deja el dato en stderr, en Loki ni en error_events', function (): void {
    /*
     * EL CAMINO COMPLETO, con una `QueryException` DE VERDAD: un alta que
     * choca con el UNIQUE de `employee_code` en PostgreSQL. Su mensaje lleva el
     * `DETAIL: Key (employee_code)=(…)` del motor y el `SQL:` con los valores
     * interpolados —nombre, DNI e IBAN en las columnas de texto—, que es
     * exactamente lo que aparecio en el log del contenedor.
     */
    app()->make(ExecutionContext::class)->reset();

    config(['logging.default' => 'stack']);
    $stderr = lokiPiiStderrAFichero();
    $transport = RecordingLokiTransport::accepting();
    enciendeElCanalLoki($transport, ['stderr', 'loki']);

    $site = WorkforceFixtures::site('Hotel de la excepcion con datos');
    WorkforceFixtures::employee($site, employeeCode: LOKI_PII_CODIGO_DE_EMPLEADO);

    Route::middleware('api')->get('/api/v1/__loki-pii/boom', static function () use ($site): never {
        try {
            DB::transaction(static function () use ($site): void {
                DB::table('employees')->insert([
                    'uuid' => (string) Str::uuid7(),
                    'site_id' => $site,
                    'first_name' => LOKI_PII_DNI,
                    'last_name' => LOKI_PII_IBAN,
                    'employee_code' => LOKI_PII_CODIGO_DE_EMPLEADO,
                    'status' => 'active',
                    'hired_at' => '2026-01-01',
                    'locale' => 'es',
                ]);
            });
        } catch (QueryException $exception) {
            // Se relanza envuelta, como hace cualquier capa que anade contexto:
            // el dato llega por el mensaje Y por `previous`.
            throw new RuntimeException('Alta fallida: '.$exception->getMessage(), 0, $exception);
        }

        throw new LogicException('El alta duplicada tenia que fallar.');
    });

    Api::guest()
        ->withHeaders(['traceparent' => '00-'.TRACE_ID_DE_LA_LINEA_ENVIADA.'-00f067aa0ba902b7-01'])
        ->get('/api/v1/__loki-pii/boom')
        ->assertStatus(500);

    app(LokiHandler::class)->flush();

    $cuerpo = cuerpoEnviadoALoki($transport);
    $lineas = lokiPiiLineasDe($stderr);
    $escrito = (string) file_get_contents($stderr);
    @unlink($stderr);
    $errores = (string) json_encode(DB::table('error_events')->get()->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // La mitad que hace significativa a la otra: los tres sitios han escrito.
    expect($lineas)->not->toBe([])
        ->and(DB::table('error_events')->count())->toBe(1)
        ->and($escrito)->toContain('SQLSTATE[23505]');

    foreach (lokiPiiProhibidos() as $prohibido) {
        expect($cuerpo)->not->toContain($prohibido)
            ->and($escrito)->not->toContain($prohibido)
            ->and($errores)->not->toContain($prohibido);
    }

    foreach ($lineas as $linea) {
        expect(data_get($linea, 'extra.trace_id'))->toBe(TRACE_ID_DE_LA_LINEA_ENVIADA);
    }
})->group('RL-08', 'RF-PD-15', 'RL-19');
