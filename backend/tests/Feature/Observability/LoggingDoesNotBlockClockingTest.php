<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Shared\Application\Port\Clock;
use App\Support\Observability\Logging\LokiHandler;
use App\Support\Observability\Logging\LokiTransport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **UN LOKI CAIDO TAMPOCO PUEDE IMPEDIR UN FICHAJE** (reglas duras 15 y 19,
 * RF-AT-10, RN-15, decision 8 de la ficha 3.1).
 *
 * ## Por que existe, teniendo ya la gemela de trazas
 *
 * `TracingDoesNotBlockClockingTest` cierra el riesgo del exportador OTLP. La
 * tarea 3.1 mete en el camino del fichaje **un segundo canal saliente**: el
 * `POST` a Loki de cada peticion. Son dos piezas distintas, con dos librerias
 * distintas y dos tiempos maximos distintos, y la garantia de una no dice nada
 * de la otra. El canal de log ademas se ejecuta en un momento diferente —el
 * `terminating()` de la aplicacion— y con un cliente HTTP distinto.
 *
 * ## Con el canal REAL, no con un doble
 *
 * `Tests\Support\Observability\RecordingLokiTransport` sirve para afirmar QUE se
 * envia; aqui hace falta lo contrario: que **la red se toque de verdad** y que
 * el fallo cueste lo que tiene que costar. Con un doble no hay socket que
 * rechazar y la afirmacion se quedaria en una revision de codigo.
 *
 * `http://127.0.0.1:9` es el puerto «descarte»: nadie escucha y la conexion se
 * rechaza al instante. El fallo malo —un Loki que acepta la conexion y no
 * contesta— lo acota el `timeout` de 1 s del handler, que es configuracion del
 * canal y no se puede simular aqui sin un servidor de mentira.
 *
 * ## Que se compara
 *
 * La respuesta con Loki caido contra la respuesta sin canal Loki, entre si y no
 * contra un literal: asi la afirmacion sigue valiendo el dia que cambie la forma
 * de la respuesta de `/scan`.
 */

uses(RefreshDatabase::class);

/** Nadie escucha ahi. Mismo destino que la gemela de trazas. */
const LOKI_INALCANZABLE = 'http://127.0.0.1:9';

const AHORA_DEL_FICHAJE_CON_LOKI = '2026-03-14 07:02:31';

const TARJETA_DEL_FICHAJE_CON_LOKI = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

/**
 * @return array{token: string, employee: string}
 */
function escenarioDeFichajeConLogs(): array
{
    $site = WorkforceFixtures::site('Hotel con Loki caido', 'Europe/Madrid');
    $employee = WorkforceFixtures::employee($site, WorkforceFixtures::department($site));
    $device = AttendanceFixtures::device($site);

    app()->instance(Clock::class, FixedClock::at(AHORA_DEL_FICHAJE_CON_LOKI));
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()->resolving(TARJETA_DEL_FICHAJE_CON_LOKI, $employee),
    );

    return ['token' => AttendanceFixtures::tokenFor($device['id']), 'employee' => $employee];
}

/**
 * @return array{status: int, body: string, seconds: float}
 */
function ficharMidiendoConLogs(string $token): array
{
    $scanId = Str::uuid7()->toString();
    $startedAt = microtime(true);

    $respuesta = Api::as($token)->withHeaders([
        'Idempotency-Key' => $scanId,
        'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
    ])->post('/api/v1/scan', [
        'scan_id' => $scanId,
        'occurred_at' => '2026-03-14T07:02:31Z',
        'qr_payload' => TARJETA_DEL_FICHAJE_CON_LOKI,
    ]);

    return [
        'status' => $respuesta->getStatusCode(),
        'body' => str_replace($scanId, '<scan_id>', (string) $respuesta->getContent()),
        'seconds' => microtime(true) - $startedAt,
    ];
}

/**
 * Enciende el canal `loki` DE VERDAD contra un destino inalcanzable.
 *
 * Se reconstruyen el handler y el canal porque los dos son singletons del
 * contenedor y `config/logging.php` ya decidio la pila al arrancar: cambiar la
 * configuracion sin olvidar las instancias dejaria el canal viejo en pie y la
 * prueba no tocaria la red.
 */
function conLokiInalcanzable(callable $body): void
{
    config([
        'logging.channels.loki.url' => LOKI_INALCANZABLE,
        'logging.channels.loki.timeout' => 1.0,
        'logging.channels.stack.channels' => ['stderr', 'loki'],
    ]);

    app()->forgetInstance(LokiHandler::class);
    app()->forgetInstance(LokiTransport::class);
    Log::forgetChannel('loki');
    Log::forgetChannel('stack');

    try {
        $body();
    } finally {
        config(['logging.channels.loki.url' => '', 'logging.channels.stack.channels' => ['stderr']]);

        app()->forgetInstance(LokiHandler::class);
        app()->forgetInstance(LokiTransport::class);
        Log::forgetChannel('loki');
        Log::forgetChannel('stack');
    }
}

it('responde igual y en tiempo razonable con Loki caido', function (): void {
    // La referencia: el mismo fichaje sin canal Loki, que es la instalacion de la
    // mayoria. Dos escenarios equivalentes y no dos escaneos del mismo: el
    // segundo escaneo de una persona es una SALIDA y su respuesta es
    // legitimamente distinta.
    $sinLoki = ficharMidiendoConLogs(escenarioDeFichajeConLogs()['token']);

    conLokiInalcanzable(function () use ($sinLoki): void {
        $escenario = escenarioDeFichajeConLogs();

        $conLokiCaido = ficharMidiendoConLogs($escenario['token']);

        expect($conLokiCaido['status'])->toBe($sinLoki['status'])
            ->and($conLokiCaido['body'])->toBe($sinLoki['body']);

        /*
         * El cliente de pruebas ejecuta `terminate()`, que es donde
         * `LoggingServiceProvider` vacia el buffer: el `POST` fallido esta DENTRO
         * del tiempo medido, que es justo lo que hay que acotar.
         *
         * Dos segundos de techo, no uno: el handler tiene un segundo de tiempo
         * maximo y el margen restante absorbe la variabilidad de un runner
         * cargado. Lo que esta prueba tiene que detectar es un canal que
         * REINTENTA o que espera indefinidamente, no cien milisegundos de mas.
         */
        expect($conLokiCaido['seconds'])->toBeLessThan(
            2.0,
            'Un Loki caido ha anadido '.round($conLokiCaido['seconds'], 3).' s al fichaje. '
            .'La regla dura 19 no lo admite: perder una linea de log es infinitamente mas barato que '
            .'retrasar un fichaje (decision 8 de la ficha 3.1).'
        );
    });
})->group('RF-AT-10', 'RN-15');

it('el fichaje sigue funcionando cuando LOKI_URL no es una URL', function (): void {
    // El fallo real de configuracion: una linea mal copiada en el `.env`. La
    // instalacion se queda sin la copia consultable del log, nunca sin fichaje.
    config([
        'logging.channels.loki.url' => 'no-es-una-url',
        'logging.channels.stack.channels' => ['stderr', 'loki'],
    ]);

    app()->forgetInstance(LokiHandler::class);
    app()->forgetInstance(LokiTransport::class);
    Log::forgetChannel('loki');
    Log::forgetChannel('stack');

    $escenario = escenarioDeFichajeConLogs();

    expect(ficharMidiendoConLogs($escenario['token'])['status'])->toBe(200);

    config(['logging.channels.loki.url' => '', 'logging.channels.stack.channels' => ['stderr']]);

    app()->forgetInstance(LokiHandler::class);
    app()->forgetInstance(LokiTransport::class);
    Log::forgetChannel('loki');
    Log::forgetChannel('stack');
})->group('RF-AT-10', 'RN-15');
