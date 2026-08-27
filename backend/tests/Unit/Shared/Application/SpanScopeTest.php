<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Support\SpanScope;
use OpenTelemetry\API\Trace\SpanKind;
use Tests\Support\Telemetry\RecordingTracer;

/*
 * El andamiaje de telemetria compartido por las siete clases de instrumentacion
 * (doc 02 §8.1).
 *
 * **Lo que hay que demostrar aqui es doble**, y por eso el fichero tiene dos
 * bloques:
 *
 *   1. **Con proveedor inerte** —la instalacion de la mayoria de los clientes,
 *      que no exportan trazas— nada de esto puede romper el acto que envuelve. La
 *      regla dura 19 no admite matices: el quiosco nunca bloquea al empleado, ni
 *      porque el exportador de trazas no responda. Perder una traza es
 *      infinitamente mas barato que perder una jornada.
 *   2. **Con proveedor real** —el cliente que si exporta— el span se cierra de
 *      verdad, el `trace_id` es el del span, y el contexto en curso vuelve a su
 *      sitio. Contra el proveedor inerte estas tres cosas son indistinguibles de
 *      no hacer nada, asi que se prueban con un tracer en memoria
 *      ({@see RecordingTracer}).
 */

// ---------------------------------------------------------------------------
// Con proveedor inerte: medir no puede romper lo que se mide.
// ---------------------------------------------------------------------------

it('no devuelve el trace_id a ceros de un span inerte', function (): void {
    // Sin SDK configurado el `trace_id` es `00000000000000000000000000000000`.
    // Escribirlo en el log seria peor que no escribir nada: PARECE un
    // identificador, asi que quien intente correlacionar un incidente lo buscara
    // en el visor de trazas y no encontrara nada, dos veces.
    $span = SpanScope::start('kronoqr.pruebas', 'acto.inerte');

    expect($span->traceId())->toBeNull();
})->group('RF-ID-06', 'RL-05');

it('tampoco devuelve el trace_id a ceros del contexto en curso', function (): void {
    expect(SpanScope::currentTraceId())->toBeNull();
})->group('RF-ID-06', 'RL-05');

it('aguanta que cierren un span inerte dos veces', function (): void {
    // Ocurre de verdad: un caso de uso que cierra en el camino feliz y otra vez
    // en un `finally`. La segunda llamada no puede ser una averia.
    $span = SpanScope::start('kronoqr.pruebas', 'acto.cerrado.dos.veces');

    $span->end(['kronoqr.outcome' => 'accepted']);
    $span->end(['kronoqr.outcome' => 'accepted']);
})->group('RF-ID-06', 'RL-05')->throwsNoExceptions();

// ---------------------------------------------------------------------------
// Con proveedor real: lo que el inerte no puede distinguir.
// ---------------------------------------------------------------------------

it('cierra el span de verdad y no solo deja de lanzar', function (): void {
    // Un `end()` que se tragara su propia excepcion dejaria el span abierto para
    // siempre y no llegaria nunca al exportador. Contra el proveedor inerte los
    // dos casos se ven igual; aqui no.
    RecordingTracer::around(function (RecordingTracer $tracer): void {
        $span = SpanScope::start('kronoqr.pruebas', 'acto.medido', SpanKind::KIND_SERVER, [
            'kronoqr.site_id' => 1,
        ]);

        expect($tracer->finishedSpans())->toHaveCount(0);

        $span->end(['kronoqr.outcome' => 'accepted']);

        $cerrados = $tracer->finishedSpans();

        expect($cerrados)->toHaveCount(1)
            ->and($cerrados[0]->getName())->toBe('acto.medido')
            ->and($cerrados[0]->getAttributes()->get('kronoqr.outcome'))->toBe('accepted');
    });
})->group('RF-ID-06', 'RL-05');

it('devuelve un trace_id real y significativo cuando hay traza de verdad', function (): void {
    RecordingTracer::around(function (): void {
        $span = SpanScope::start('kronoqr.pruebas', 'acto.con.traza');
        $traceId = $span->traceId();
        $span->end();

        expect($traceId)->toBeString()
            ->and($traceId)->toHaveLength(32)
            ->and($traceId)->not->toBe(str_repeat('0', 32));
    });
})->group('RF-ID-06', 'RL-05');

it('ignora un atributo sin nombre en lugar de tirar la medicion entera', function (): void {
    // La API de OpenTelemetry exige clave no vacia y el SDK real se queja. Un
    // atributo mal construido —una clave que sale de una variable vacia— no puede
    // llevarse por delante el span ni, con el, el acto medido.
    RecordingTracer::around(function (RecordingTracer $tracer): void {
        $span = SpanScope::start('kronoqr.pruebas', 'acto.sin.nombre', SpanKind::KIND_SERVER, [
            '' => 'valor sin clave',
            'kronoqr.site_id' => 1,
        ]);

        $span->end(['' => 'otro sin clave', 'kronoqr.outcome' => 'accepted']);

        $cerrados = $tracer->finishedSpans();

        // El span existe, llego al exportador, y conserva los atributos que si
        // tenian nombre.
        expect($cerrados)->toHaveCount(1)
            ->and($cerrados[0]->getAttributes()->get('kronoqr.site_id'))->toBe(1)
            ->and($cerrados[0]->getAttributes()->get('kronoqr.outcome'))->toBe('accepted');

        // **Y el atributo sin nombre no esta.** Sin esta linea la prueba no
        // comprobaria nada: el SDK acepta la clave vacia sin quejarse, asi que
        // quitando la guarda de `SpanScope` los tres `expect` de arriba seguirian
        // en verde. Comprobado a proposito rompiendo la implementacion.
        expect($cerrados[0]->getAttributes()->has(''))->toBeFalse();
    });
})->group('RF-ID-06', 'RL-05');

it('no deja fuga de contexto tras cerrar un span activado', function (): void {
    // **La fuga de scope.** `startActive()` empuja el span al contexto en curso y
    // `end()` tiene que soltarlo. Si no lo soltara, el siguiente acto de la MISMA
    // peticion colgaria de un span ya cerrado: en el quiosco, cada fichaje de la
    // cola offline quedaria anidado dentro del anterior.
    RecordingTracer::around(function (): void {
        $antes = SpanScope::currentTraceId();

        $span = SpanScope::startActive('kronoqr.pruebas', 'acto.activo');
        $span->end();

        expect(SpanScope::currentTraceId())->toBe($antes);
    });
})->group('RF-ID-06', 'RL-05');

it('hace coincidir el trace_id del contexto con el del span mientras esta activo', function (): void {
    // La razon de ser de `startActive()`, y el defecto que corrige: con un span NO
    // activado y una peticion sin `traceparent`, quien escribia un apunte leyendo
    // el contexto obtenia un `trace_id` distinto del de la traza. Aqui se ve la
    // diferencia entre los dos constructores, que es lo que hace significativa la
    // prueba.
    RecordingTracer::around(function (): void {
        $activo = SpanScope::startActive('kronoqr.pruebas', 'acto.activo');

        expect(SpanScope::currentTraceId())->toBe($activo->traceId());

        $activo->end();

        // El contrapunto: `start()` NO activa, asi que el contexto sigue sin
        // conocer esa traza. Sin este caso, la prueba de arriba pasaria igual con
        // las dos formas de abrir y no demostraria nada.
        $pasivo = SpanScope::start('kronoqr.pruebas', 'acto.pasivo');

        expect(SpanScope::currentTraceId())->not->toBe($pasivo->traceId());

        $pasivo->end();
    });
})->group('RF-ID-06', 'RL-05');
