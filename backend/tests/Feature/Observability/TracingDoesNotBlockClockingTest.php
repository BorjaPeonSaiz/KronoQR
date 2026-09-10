<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Shared\Application\Port\Clock;
use App\Support\Observability\Tracing\TracerFactory;
use Illuminate\Support\Str;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **UN COLECTOR DE TRAZAS CAIDO NO PUEDE IMPEDIR UN FICHAJE** (reglas duras 15 y
 * 19, RF-AT-10, decision 5 de la ficha 3.1).
 *
 * ## El riesgo que esto cierra
 *
 * La tarea 3.1 mete en el camino del fichaje un SDK con un exportador HTTP. A
 * partir de ahi, «el quiosco nunca bloquea al empleado» deja de ser una
 * propiedad del codigo de fichaje y pasa a depender tambien de que el
 * exportador se rinda a tiempo. Eso no se sostiene con una revision de codigo:
 * se sostiene apuntando el exportador a un destino que **no existe** y
 * comprobando que la respuesta es la misma, byte a byte, y llega en el mismo
 * orden de tiempo.
 *
 * `http://127.0.0.1:9` es el puerto «descarte» de la maquina: nadie escucha y la
 * conexion se rechaza al instante, que es el fallo mas benigno. El fallo malo
 * —un colector que acepta la conexion y no contesta— lo acota el tiempo maximo
 * de `OTEL_EXPORTER_OTLP_TIMEOUT`, que es configuracion y no se puede simular
 * aqui sin un servidor de mentira.
 *
 * ## Por que se fuerza el vaciado dentro de la medicion
 *
 * `BatchSpanProcessor` acumula y envia al cerrar el proceso, asi que una prueba
 * que solo hiciera la peticion no llegaria a tocar la red y no probaria nada. Se
 * fuerza el envio con el reloj en marcha: lo que se mide es el coste completo de
 * un colector caido, no solo el de encolar spans.
 */

uses(RefreshDatabase::class);

const AHORA_DEL_FICHAJE = '2026-03-14 07:02:31';

const TARJETA_DEL_FICHAJE = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

/** Nadie escucha ahi. Es el destino inalcanzable de la ficha. */
const COLECTOR_INALCANZABLE = 'http://127.0.0.1:9';

/**
 * @return array{token: string, employee: string}
 */
function escenarioDeFichajeConTrazas(): array
{
    $site = WorkforceFixtures::site('Hotel con colector caido', 'Europe/Madrid');
    $employee = WorkforceFixtures::employee($site, WorkforceFixtures::department($site));
    $device = AttendanceFixtures::device($site);

    app()->instance(Clock::class, FixedClock::at(AHORA_DEL_FICHAJE));
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()->resolving(TARJETA_DEL_FICHAJE, $employee),
    );

    return ['token' => AttendanceFixtures::tokenFor($device['id']), 'employee' => $employee];
}

/**
 * Un fichaje tal y como lo envia el quiosco, con su cabecera de traza.
 *
 * @return array{status: int, body: string, seconds: float}
 */
function ficharMidiendo(string $token, string $occurredAt): array
{
    $scanId = Str::uuid7()->toString();
    $startedAt = microtime(true);

    $respuesta = Api::as($token)->withHeaders([
        'Idempotency-Key' => $scanId,
        'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
    ])->post('/api/v1/scan', [
        'scan_id' => $scanId,
        'occurred_at' => $occurredAt,
        'qr_payload' => TARJETA_DEL_FICHAJE,
    ]);

    return [
        'status' => $respuesta->getStatusCode(),
        // Sin el `scan_id`, que cambia por definicion: lo que se compara es la
        // FORMA de la respuesta, no el identificador del escaneo.
        'body' => str_replace($scanId, '<scan_id>', (string) $respuesta->getContent()),
        'seconds' => microtime(true) - $startedAt,
    ];
}

/**
 * Instala un proveedor de trazas de verdad apuntando a un destino inalcanzable, y
 * lo desinstala pase lo que pase.
 *
 * @param  callable(TracerProvider): void  $body
 */
function conColectorInalcanzable(callable $body): void
{
    $provider = (new TracerFactory(
        COLECTOR_INALCANZABLE,
        'kronoqr-api',
        '0.0.0',
        'testing',
        1.0,
        2.0,
    ))->build();

    expect($provider)->toBeInstanceOf(TracerProvider::class);
    assert($provider instanceof TracerProvider);

    // `Globals` cachea el proveedor la primera vez que alguien lo pide: sin el
    // par reset/reset, esta prueba decidiria por todas las demas del proceso.
    Globals::reset();
    Globals::registerInitializer(
        static fn (Configurator $configurator): Configurator => $configurator->withTracerProvider($provider),
    );

    try {
        $body($provider);
    } finally {
        Globals::reset();
    }
}

it('responde exactamente igual y en tiempo razonable con el colector de trazas caido', function (): void {
    // La referencia: el mismo fichaje sin SDK, que es la instalacion de la
    // mayoria. Se comparan las dos respuestas entre si y no contra un literal,
    // que es lo que hace que la afirmacion siga valiendo si la respuesta cambia.
    //
    // Dos escenarios equivalentes y no dos escaneos del mismo: el segundo
    // escaneo de una persona es una SALIDA y su respuesta es legitimamente
    // distinta. Lo que tiene que salir identico es el mismo acto.
    $sinTrazas = ficharMidiendo(escenarioDeFichajeConTrazas()['token'], '2026-03-14T07:02:31Z');

    conColectorInalcanzable(function (TracerProvider $provider) use ($sinTrazas): void {
        $escenario = escenarioDeFichajeConTrazas();

        $startedAt = microtime(true);

        $conColectorCaido = ficharMidiendo($escenario['token'], '2026-03-14T07:02:31Z');

        // El envio de verdad: sin esto los spans se quedarian en la cola y el
        // exportador no habria tocado la red. Cuenta dentro del tiempo medido.
        $provider->shutdown();

        $totalConColectorCaido = microtime(true) - $startedAt;

        expect($conColectorCaido['status'])->toBe($sinTrazas['status'])
            ->and($conColectorCaido['body'])->toBe($sinTrazas['body']);

        // Un segundo de techo con el colector caido, vaciado incluido. El
        // exportador no reintenta (`maxRetries = 0`): un destino que rechaza la
        // conexion cuesta un intento fallido y nada mas.
        expect($totalConColectorCaido)->toBeLessThan(
            1.0,
            'Un colector de trazas caido ha anadido '.round($totalConColectorCaido, 3).' s al fichaje. '
            .'La regla dura 15 y la 19 no lo admiten: la instrumentacion no puede abrir un camino en el que '
            .'el fichaje falle o se retrase porque el exportador no responda.'
        );
    });
})->group('RF-AT-10', 'RN-15');

it('el fichaje sigue funcionando cuando el endpoint declarado no es una URL', function (): void {
    // El otro fallo real de configuracion: `OTEL_EXPORTER_OTLP_ENDPOINT=tempo:4318`
    // —sin esquema— en el `.env` de un cliente. La fabrica devuelve `null` en vez
    // de propagar, y la instalacion se queda sin trazas, no sin fichaje.
    $factory = new TracerFactory('no-es-una-url', 'kronoqr-api', '0.0.0', 'testing', 1.0, 2.0);

    expect($factory->enabled())->toBeTrue()
        ->and($factory->build())->toBeNull();

    $escenario = escenarioDeFichajeConTrazas();

    expect(ficharMidiendo($escenario['token'], '2026-03-14T07:02:31Z')['status'])->toBe(200);
})->group('RF-AT-10', 'RN-15');

it('sin endpoint no se construye nada, que es el estado de serie', function (): void {
    // La instalacion de la mayoria: sin destino, el SDK no existe y la
    // instrumentacion que ya habia cuesta lo que costaba, que es nada.
    $factory = new TracerFactory('', 'kronoqr-api', '0.0.0', 'testing', 1.0, 2.0);

    expect($factory->enabled())->toBeFalse()
        ->and($factory->build())->toBeNull();
})->group('RF-AT-10');
