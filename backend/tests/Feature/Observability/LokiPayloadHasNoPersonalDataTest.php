<?php

declare(strict_types=1);

use App\Support\Observability\Logging\CorrelationOnlyExtra;
use App\Support\Observability\Logging\LokiHandler;
use App\Support\Observability\Logging\LokiLogChannel;
use App\Support\Observability\Logging\LokiTransport;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Tests\Support\Observability\RecordingLokiTransport;

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
    config(['logging.channels.loki.url' => '', 'logging.channels.stack.channels' => ['stderr']]);

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
