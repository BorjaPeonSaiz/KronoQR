<?php

declare(strict_types=1);

namespace Tests\Support\Telemetry;

use ArrayObject;
use OpenTelemetry\API\Globals;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * Un proveedor de trazas **de verdad**, en memoria, para las pruebas de
 * `SpanScope`.
 *
 * ## Por que hace falta
 *
 * Sin SDK configurado, `Globals::tracerProvider()` devuelve un proveedor inerte:
 * cada span es un objeto que acepta cualquier cosa sin quejarse y cuyo `trace_id`
 * son treinta y dos ceros. Una prueba contra ese proveedor no puede distinguir
 * una implementacion defensiva de una que no lo es —ninguna de las dos falla— ni
 * comprobar que el contexto en curso vuelve a su sitio, porque no hay contexto
 * que mover.
 *
 * Es decir: contra el proveedor inerte, la mitad de lo que `SpanScope` promete
 * no se puede comprobar. Con este, si.
 *
 * ## El proveedor inerte tambien se prueba, y aparte
 *
 * No es un caso degradado: **es la instalacion de la mayoria de los clientes**,
 * que no exportan trazas. Las pruebas que lo cubren no usan esta clase, y por eso
 * el fichero de pruebas tiene los dos bloques separados.
 *
 * ## Aislamiento
 *
 * `Globals` cachea el proveedor la primera vez que alguien lo pide, asi que
 * {@see self::install()} lo reinicia antes de registrar el suyo y
 * {@see self::uninstall()} lo deja como estaba. Sin ese par, la primera prueba
 * que tocara telemetria decidiria por todas las demas del proceso — que es la
 * definicion de prueba intermitente.
 */
final class RecordingTracer
{
    /**
     * @param  ArrayObject<int, ImmutableSpan>  $storage
     */
    private function __construct(private readonly ArrayObject $storage) {}

    /**
     * Ejecuta el cuerpo con el tracer instalado y lo desinstala pase lo que pase.
     *
     * **Es la unica forma de usar esta clase desde una prueba**, y por dos
     * motivos. El primero es el aislamiento: un `afterEach` que dependiera de que
     * el cuerpo haya llegado al final dejaria el tracer global puesto en cuanto
     * una prueba fallara, y la siguiente mediria dentro de la traza de la
     * anterior. El `finally` no depende de eso.
     *
     * El segundo es de tipos: guardar el tracer en `$this->tracer` dentro de una
     * clausura de Pest no compila en PHPStan 9 —ahi `$this` es un `TestCall` y no
     * el caso de prueba—, que es el mismo motivo por el que existen
     * `Tests\Support\Http\Api` y `Tests\Feature\Quality\Support\Commands`.
     *
     * @param  callable(self): void  $body
     */
    public static function around(callable $body): void
    {
        $tracer = self::install();

        try {
            $body($tracer);
        } finally {
            $tracer->uninstall();
        }
    }

    public static function install(): self
    {
        /** @var ArrayObject<int, ImmutableSpan> $storage */
        $storage = new ArrayObject;

        $provider = new TracerProvider(
            new SimpleSpanProcessor(new InMemoryExporter($storage)),
        );

        Globals::reset();
        Globals::registerInitializer(
            static fn (object $configurator): object => $configurator->withTracerProvider($provider),
        );

        return new self($storage);
    }

    public function uninstall(): void
    {
        Globals::reset();
    }

    /**
     * Los spans **ya cerrados**. Un span abierto no ha llegado al exportador
     * todavia, que es justamente lo que permite afirmar que `end()` cerro de
     * verdad y no solo dejo de lanzar.
     *
     * @return list<ImmutableSpan>
     */
    public function finishedSpans(): array
    {
        return array_values(iterator_to_array($this->storage));
    }
}
