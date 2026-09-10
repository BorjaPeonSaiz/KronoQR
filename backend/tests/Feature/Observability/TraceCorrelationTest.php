<?php

declare(strict_types=1);

use App\Modules\Product\Infrastructure\Capture\ExecutionContext;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Observability\CapturedLog;

/*
 * **LA MISMA TRAZA EN LOS DOS REGISTROS** (doc 02 §8.1 y §8.2.1, RF-PD-15,
 * tarea 3.1).
 *
 * ## Que promete el §8.1 y que se comprueba aqui
 *
 * Que se puede seguir una peticion *«desde el `fetch` del navegador del quiosco
 * hasta la consulta SQL»*. El eslabon que hace posible todo lo demas es este: el
 * `traceparent` que envia el cliente tiene que **aparecer con el mismo
 * identificador en el log tecnico y en la fila de `error_events`**. Sin eso, el
 * IT del cliente ve un error en su panel y no tiene forma de encontrar en Loki la
 * peticion que lo produjo — que es justo lo que se hace cuando alguien dice «el
 * empleado dice que ficho a las 07:02».
 *
 * Son dos almacenes distintos a proposito (§8.2.1): Loki es opcional en la
 * instalacion de un cliente y `error_events` viaja en la copia de seguridad y en
 * el paquete de diagnostico. El `trace_id` es lo unico que los une.
 *
 * ## Se captura el canal de log, no el facade
 *
 * Ver {@see CapturedLog}: lo que hay que comprobar es lo que sale **despues** de
 * los processors, que es donde el `trace_id` se anade. Un `Log::spy()` afirmaria
 * sobre un registro que no es el que se escribe.
 *
 * ## Sin SDK de trazas esto sigue funcionando
 *
 * Y es lo importante, porque es la instalacion de la mayoria: el identificador
 * sale de la cabecera del cliente, no de un exportador. Con SDK, el span de
 * servidor cuelga de esa misma traza y el identificador es el mismo.
 */

uses(RefreshDatabase::class);

/** El ejemplo del W3C, que es reconocible de un vistazo en un volcado. */
const TRACEPARENT_DEL_QUIOSCO = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

const TRAZA_W3C_DEL_QUIOSCO = '4bf92f3577b34da6a3ce929d0e0e4736';

const RUTA_QUE_REVIENTA_CON_TRAZA = '/api/v1/__trace/boom';

beforeEach(function (): void {
    // El contexto de captura es un singleton por proceso: sin reiniciarlo, el
    // marco de la prueba anterior daria el origen equivocado.
    app()->make(ExecutionContext::class)->reset();

    Route::middleware('api')->get(
        RUTA_QUE_REVIENTA_CON_TRAZA,
        static fn () => throw new RuntimeException('El adaptador no respondio'),
    )->name('trace.boom');
});

it('el trace_id que envia el cliente aparece en el log de la peticion y en error_events', function (): void {
    CapturedLog::around(function (CapturedLog $log): void {
        Api::guest()
            ->withHeaders(['traceparent' => TRACEPARENT_DEL_QUIOSCO])
            ->get(RUTA_QUE_REVIENTA_CON_TRAZA)
            ->assertStatus(500);

        // El informe de la excepcion escribe en el log; esa linea es la que el IT
        // del cliente buscara con el `trace_id` que le da su panel de errores.
        expect($log->records())->not->toBe([])
            ->and($log->dump())->toContain(TRAZA_W3C_DEL_QUIOSCO);

        foreach ($log->traceIds() as $traceId) {
            expect($traceId)->toBe(TRAZA_W3C_DEL_QUIOSCO);
        }
    });

    $errores = DB::table('error_events')->get();

    expect($errores)->toHaveCount(1)
        ->and($errores->first()?->trace_id)->toBe(TRAZA_W3C_DEL_QUIOSCO);
})->group('RF-PD-15', 'RL-08');

it('toda linea de log de una peticion con traceparent lleva su trace_id, la escriba quien la escriba', function (): void {
    // La diferencia con la version anterior de la instrumentacion: antes solo lo
    // llevaban los apuntes de las clases `*Telemetry`. Un `Log::warning` suelto en
    // un controlador salia sin nada con que unirlo a la peticion.
    Route::middleware('api')->get('/api/v1/__trace/log', static function (): array {
        Log::warning('prueba.linea_suelta', ['detalle' => 'sin trace_id propio']);

        return ['ok' => true];
    })->name('trace.log');

    CapturedLog::around(function (CapturedLog $log): void {
        Api::guest()
            ->withHeaders(['traceparent' => TRACEPARENT_DEL_QUIOSCO])
            ->get('/api/v1/__trace/log')
            ->assertOk();

        $lineas = $log->records();

        expect($lineas)->toHaveCount(1)
            ->and($lineas[0]->message)->toBe('prueba.linea_suelta')
            ->and($lineas[0]->extra['trace_id'] ?? null)->toBe(TRAZA_W3C_DEL_QUIOSCO);
    });
})->group('RF-PD-15', 'RL-08');

it('no inventa un trace_id cuando la peticion no trae traceparent', function (): void {
    // Un identificador inventado por peticion seria peor que ninguno: parece que
    // correlaciona y no correlaciona con nada del cliente. Sin SDK y sin cabecera,
    // el campo no esta.
    CapturedLog::around(function (CapturedLog $log): void {
        Api::guest()->get(RUTA_QUE_REVIENTA_CON_TRAZA)->assertStatus(500);

        foreach ($log->records() as $record) {
            $traceId = $record->extra['trace_id'] ?? null;

            // Con SDK configurado el servidor abre su propia traza, y entonces el
            // identificador existe pero **no es el de ningun cliente**. Lo que no
            // puede pasar es que sea el de otra peticion.
            expect($traceId)->not->toBe(TRAZA_W3C_DEL_QUIOSCO);
        }
    });

    $error = DB::table('error_events')->first();

    expect($error?->trace_id)->not->toBe(TRAZA_W3C_DEL_QUIOSCO);
})->group('RF-PD-15');

it('una cabecera traceparent malformada no rompe la peticion', function (): void {
    // Regla dura 19: la observabilidad no puede convertir una peticion correcta en
    // un error. Lo mas facil de romper es lo que llega de fuera.
    Route::middleware('api')->get('/api/v1/__trace/ok', static fn (): array => ['ok' => true])->name('trace.ok');

    foreach (['no-es-un-traceparent', '', '00-xxxx-yyyy-zz'] as $cabecera) {
        Api::guest()
            ->withHeaders(['traceparent' => $cabecera])
            ->get('/api/v1/__trace/ok')
            ->assertOk();
    }
})->group('RF-PD-15');

it('una cabecera traceparent arbitraria no llega al log ni a Loki', function (): void {
    /*
     * **EL DEFECTO QUE ESTA PRUEBA CIERRA** (RL-08, regla dura 21).
     *
     * `PropagateTraceContext` publicaba el `traceparent` entrante EN CRUDO en el
     * `Context` de Laravel. De ahi, `ContextLogProcessor` lo copia a **cada
     * linea de log de la peticion** y el `LokiHandler` lo empuja a Loki, donde se
     * queda los 90 dias de la retencion (RL-11).
     *
     * Consecuencia: cualquier peticion anonima, sin token y sin cuenta, era un
     * canal de escritura arbitraria en el almacen de logs del cliente. Ocho
     * kilobytes de cabecera, multiplicados por las lineas de la peticion,
     * multiplicados por las peticiones que quisiera hacer quien la enviara.
     *
     * La correccion es la forma: si no casa con el patron del W3C, no se publica.
     */
    Route::middleware('api')->get('/api/v1/__trace/basura', static function (): array {
        Log::warning('prueba.linea_con_cabecera_rara', []);

        // Lo que el middleware dejo en el `Context`: es el punto exacto del
        // defecto, antes de que ningun processor lo copie a la linea.
        return ['contexto' => array_keys(Context::all())];
    })->name('trace.basura');

    $basura = 'X'.str_repeat('A', 512);

    CapturedLog::around(function (CapturedLog $log) use ($basura): void {
        $respuesta = Api::guest()
            ->withHeaders(['traceparent' => $basura])
            ->get('/api/v1/__trace/basura');

        $respuesta->assertOk();

        // Sin SDK —el estado de la suite y el de la mayoria de las
        // instalaciones— la clave no se publica en absoluto. Con SDK se
        // publicaria el `traceparent` del span propio, nunca el de fuera.
        expect($respuesta->json('contexto'))->not->toContain('traceparent');

        $lineas = $log->records();

        expect($lineas)->toHaveCount(1)
            // Ni la cabecera entera, ni un trozo suyo, ni por otra clave.
            ->and($log->dump())->not->toContain($basura)
            ->and($lineas[0]->extra['traceparent'] ?? null)->not->toBe($basura);
    });
})->group('RF-PD-15', 'RL-08');
