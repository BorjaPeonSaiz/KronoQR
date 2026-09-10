<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Support\SpanScope;
use App\Support\Observability\Tracing\QueuedTraceContext;
use App\Support\Observability\Tracing\TraceContext;
use App\Support\Observability\Tracing\TracerFactory;
use App\Support\Observability\Tracing\TracingServiceProvider;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Context;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Telemetry\RecordingTracer;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **«DESDE EL `fetch` DEL NAVEGADOR HASTA LA CONSULTA SQL»** (doc 02 §8.1,
 * decision 6 de la ficha 3.1).
 *
 * El tramo que faltaba de esa frase es el ultimo, y trae consigo el riesgo mas
 * serio de toda la tarea: **los valores enlazados de una consulta son datos
 * personales**. Un `insert into employees … values ('Maria', 'Gonzalez Perez',
 * …)` dentro de un atributo de traza es la regla dura 21 rota en el unico sitio
 * donde nadie mira, porque una traza no se lee como se lee un log.
 *
 * Aqui se afirman las tres cosas que sostienen ese tramo: que el span existe, que
 * lleva la consulta **con marcadores** y que no lleva ni uno de sus valores.
 *
 * ## Y las dos costuras que no se ven en una peticion
 *
 * La cola y el planificador. Los actos que de verdad hay que poder seguir de
 * punta a punta —la reconciliacion nocturna, la verificacion de la cadena— no
 * ocurren dentro de una peticion HTTP: ocurren en un worker horas despues. Si el
 * `traceparent` no cruza la cola, esa traza empieza de cero y no se puede unir con
 * lo que la pidio.
 */

uses(RefreshDatabase::class);

/**
 * Enciende la instrumentacion como si `OTEL_EXPORTER_OTLP_ENDPOINT` tuviera
 * valor, y ejecuta el cuerpo con un tracer en memoria.
 *
 * Hace falta porque los oyentes de consulta y de planificador **solo se
 * registran cuando hay destino** (decision 5): en la instalacion sin colector no
 * se paga por lo que no se exporta, asi que la prueba tiene que declarar uno.
 *
 * @param  callable(RecordingTracer): void  $body
 */
function conInstrumentacionEncendida(callable $body): void
{
    config()->set('tracing.endpoint', 'http://127.0.0.1:9');
    app()->forgetInstance(TracerFactory::class);

    (new TracingServiceProvider(app()))->boot();

    RecordingTracer::around($body);
}

/**
 * @param  list<ImmutableSpan>  $spans
 * @return list<ImmutableSpan>
 */
function spansLlamados(array $spans, string $prefix): array
{
    return array_values(array_filter(
        $spans,
        static fn (ImmutableSpan $span): bool => str_starts_with($span->getName(), $prefix),
    ));
}

it('abre un span por consulta con la consulta en marcadores y sin un solo valor enlazado', function (): void {
    conInstrumentacionEncendida(function (RecordingTracer $tracer): void {
        $site = WorkforceFixtures::site('Hotel de las trazas SQL');

        WorkforceFixtures::employee($site, null, 'active', 'Maria', 'Gonzalez Perez', 'EMP-0042');

        $consultas = spansLlamados($tracer->finishedSpans(), 'postgresql');

        expect($consultas)->not->toBe([]);

        $textos = [];

        foreach ($consultas as $span) {
            $atributos = $span->getAttributes()->toArray();

            expect($atributos['db.system'] ?? null)->toBe('postgresql')
                ->and($atributos['db.operation'] ?? null)->toBeIn(
                    ['select', 'insert', 'update', 'delete', 'begin', 'commit', 'rollback', 'other']
                );

            $textos[] = (string) ($atributos['db.query.text'] ?? '');
        }

        $todas = implode("\n", $textos);

        // Lo que tiene que estar: la forma de la consulta, que es lo que hace
        // diagnosticable una lentitud.
        expect($todas)->toContain('insert into')
            ->and($todas)->toContain('?');

        // Y lo que no puede estar, pase lo que pase (regla dura 21).
        foreach (['Maria', 'Gonzalez Perez', 'EMP-0042'] as $dato) {
            expect($todas)->not->toContain($dato);
        }
    });
})->group('RF-PD-15', 'RL-08');

it('abre y cierra un span por comando programado, con el nombre del comando y sin sus argumentos', function (): void {
    conInstrumentacionEncendida(function (RecordingTracer $tracer): void {
        $tarea = app()->make(Schedule::class)->command('compliance:apply-retention --dry-run');

        event(new ScheduledTaskStarting($tarea));
        event(new ScheduledTaskFinished($tarea, 0.5));

        $spans = spansLlamados($tracer->finishedSpans(), 'schedule ');

        expect($spans)->toHaveCount(1)
            ->and($spans[0]->getName())->toBe('schedule compliance:apply-retention')
            ->and($spans[0]->getAttributes()->get('schedule.outcome'))->toBe('finished');
    });
})->group('RF-PD-15');

it('el traceparent viaja con el trabajo encolado y el worker sigue la misma traza', function (): void {
    conInstrumentacionEncendida(function (RecordingTracer $tracer): void {
        /*
         * Se ejercita el mecanismo real —`Context::dehydrate()` y
         * `Context::hydrate()`, que son los que Laravel llama al serializar y al
         * ejecutar un trabajo— en vez de levantar un worker: lo que se afirma es
         * que el `traceparent` entra en la carga util y que al rehidratarlo el
         * contexto en curso vuelve a ser el mismo.
         */
        $abierto = SpanScope::startActive('kronoqr.test', 'peticion');
        $trazaDeLaPeticion = TraceContext::currentTraceId();

        expect($trazaDeLaPeticion)->toBeString();

        $carga = Context::dehydrate();

        $abierto->end();

        expect($carga)->toBeArray();
        assert(is_array($carga));

        // El `traceparent` viaja como un dato mas del contexto, que es lo que
        // ademas hace que las lineas de log del worker lleven `trace_id`.
        expect(json_encode($carga))->toContain('traceparent');

        Context::flush();
        Context::hydrate($carga);

        expect(TraceContext::currentTraceId())->toBe($trazaDeLaPeticion);

        // Quien lo abre lo cierra: en el worker lo hace el evento de fin de
        // trabajo, y aqui a mano. Un ambito que se queda puesto contamina al
        // siguiente trabajo del mismo proceso, que es exactamente lo que este
        // mecanismo tiene que evitar.
        app()->make(QueuedTraceContext::class)->release();
    });
})->group('RF-PD-15');
