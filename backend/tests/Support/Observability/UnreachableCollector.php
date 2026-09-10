<?php

declare(strict_types=1);

namespace Tests\Support\Observability;

use App\Support\Observability\Tracing\TracerFactory;
use App\Support\Observability\Tracing\TracingServiceProvider;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\SDK\Trace\TracerProvider;
use RuntimeException;

/**
 * Enciende el SDK de trazas **de verdad** contra un colector que no existe, y lo
 * apaga pase lo que pase.
 *
 * ## Por que un destino inalcanzable y no un doble
 *
 * Porque lo que hay que demostrar (reglas duras 15 y 19, decision 5 de la ficha
 * 3.1) es que **la instrumentacion no abre un camino en el que el fichaje falle
 * o se retrase porque el exportador no responda**. Con un exportador de mentira
 * no hay red que tocar y la afirmacion se queda en una revision de codigo.
 *
 * `http://127.0.0.1:9` es el puerto «descarte»: nadie escucha y la conexion se
 * rechaza al instante. El fallo malo —un colector que acepta la conexion y no
 * contesta— lo acota `OTEL_EXPORTER_OTLP_TIMEOUT`, que es configuracion y no se
 * puede simular sin un servidor de mentira.
 *
 * ## Se encienden las DOS mitades
 *
 * El `TracerProvider` en `Globals` —para que los spans se construyan y se
 * exporten— **y los oyentes del proveedor de servicios**, que solo se registran
 * cuando hay destino declarado: sin ellos no habria span por consulta SQL y la
 * prueba mediria la mitad del coste.
 *
 * ## `Globals::reset()` a la entrada y a la salida
 *
 * `Globals` cachea el proveedor la primera vez que alguien lo pide y acumula
 * inicializadores. Sin el par, esta ayuda decidiria por todas las demas pruebas
 * del proceso, que es como se fabrica una suite intermitente.
 */
final class UnreachableCollector
{
    /** Nadie escucha ahi: el puerto «descarte» de la maquina. */
    public const string ENDPOINT = 'http://127.0.0.1:9';

    /**
     * @param  callable(TracerProvider): void  $body
     */
    public static function around(callable $body): void
    {
        config()->set('tracing.endpoint', self::ENDPOINT);
        app()->forgetInstance(TracerFactory::class);

        // Los oyentes de consulta, cola y planificador. Sin destino declarado no
        // se registran, y esa es justo la instalacion de la mayoria.
        (new TracingServiceProvider(app()))->boot();

        $provider = (new TracerFactory(
            self::ENDPOINT,
            'kronoqr-api',
            '0.0.0',
            'testing',
            1.0,
            2.0,
        ))->build();

        if (! $provider instanceof TracerProvider) {
            throw new RuntimeException(
                'El SDK de trazas no se ha podido construir contra '.self::ENDPOINT.'. '
                .'Sin proveedor real, la prueba no comprobaria nada.',
            );
        }

        self::resetGlobals();
        Globals::registerInitializer(
            static fn (Configurator $configurator): Configurator => $configurator->withTracerProvider($provider),
        );

        try {
            $body($provider);
        } finally {
            self::resetGlobals();
            config()->set('tracing.endpoint', '');
            app()->forgetInstance(TracerFactory::class);
        }
    }

    /**
     * `Globals::reset()` **y** la bandera de {@see TracingServiceProvider}.
     *
     * Las dos o ninguna: sin la segunda, el proveedor sigue creyendo que ya
     * registro el SDK en este proceso y no vuelve a registrarlo nunca. El resto
     * de la suite corre entonces con instrumentacion inerte y ninguna prueba lo
     * dice.
     */
    private static function resetGlobals(): void
    {
        Globals::reset();
        TracingServiceProvider::forgetRegistration();
    }
}
