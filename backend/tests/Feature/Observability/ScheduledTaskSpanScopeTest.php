<?php

declare(strict_types=1);

use App\Support\Observability\Tracing\ScheduledTaskSpans;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledTask;
use Illuminate\Console\Scheduling\Schedule;
use OpenTelemetry\Context\Context;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Observability\UnreachableCollector;

/*
 * **EL SPAN DEL PLANIFICADOR NO PUEDE DEJAR SU `Scope` COLGANDO** (decision 6 de
 * la ficha 3.1, regla dura 19).
 *
 * ## El fallo que este fichero fija, ya corregido
 *
 * `ScheduledTaskSpans::starting()` abria el span con `startActive()`, que
 * **activa** el contexto, y solo lo desasocia `end()`, al que se llega desde
 * `ScheduledTaskFinished`, `ScheduledTaskSkipped` o `ScheduledTaskFailed`.
 * Cuando ese segundo evento no llega —el proceso muere a mitad, o quien emite
 * solo conoce la primera mitad del par— el `Scope` se quedaba atado al contexto
 * del proceso.
 *
 * Lo que ocurre despues es peor que perder una traza: el `DebugScope` de
 * OpenTelemetry emite `Scope: missing call to Scope::detach()` **al recolectar
 * el objeto**, en un momento cualquiera, y el manejador de errores de Laravel lo
 * promociona a `ErrorException`. La excepcion no aparece donde se origino sino
 * **dentro de la siguiente prueba que estuviera corriendo**, que es la
 * definicion exacta de prueba intermitente. Medido el 10-09-2026:
 * `tests/Feature/Product` daba 195 fallos con el SDK encendido.
 *
 * Los dos emisores que lo disparaban son
 * `tests/Feature/Product/ErrorCaptureTest.php` y
 * `tests/Feature/Product/ErrorEventsHaveNoPersonalDataTest.php`, que emiten
 * `ScheduledTaskStarting` suelto a proposito: lo que prueban es la ATRIBUCION
 * del error al planificador, no el ciclo de vida de una tarea. Que un emisor
 * legitimo pueda envenenar el proceso entero es el defecto, no el emisor.
 *
 * ## La correccion, y lo que cuesta
 *
 * El span se abre con `SpanScope::start()`, **sin activar**: se mide la tarea y
 * no se toca el contexto del proceso. Lo que se pierde es que las consultas de
 * una tarea en primer plano cuelguen de su span; casi todas las tareas de
 * `routes/console.php` llevan `runInBackground()` y corren en otro proceso,
 * donde el scope de este nunca las habria alcanzado. Ver el docblock de
 * {@see ScheduledTaskSpans}.
 *
 * ## Se llama a la clase, no se emiten eventos
 *
 * A proposito. Dentro del contenedor de desarrollo `TracingServiceProvider`
 * puede haber registrado ya sus oyentes, asi que un `event()` aqui llegaria a
 * **dos** oyentes y la afirmacion hablaria de la duplicacion y no del ciclo de
 * vida del span. Con la instancia delante, lo que se prueba es la clase.
 */

uses(RefreshDatabase::class);

/** Una tarea del planificador de verdad, con su comando y su expresion cron. */
function tareaProgramada(): ScheduledTask
{
    return app()->make(Schedule::class)->command('compliance:apply-retention');
}

it('no toca el contexto del proceso cuando la tarea programada emite su par de eventos', function (): void {
    // El camino normal: se mide la tarea entera y el contexto en curso sigue
    // siendo el mismo antes, durante y despues. Medir no mueve nada.
    UnreachableCollector::around(function (): void {
        $spans = new ScheduledTaskSpans;
        $tarea = tareaProgramada();
        $antes = Context::getCurrent();

        $spans->starting(new ScheduledTaskStarting($tarea));

        expect(Context::getCurrent())->toBe($antes);

        $spans->finished(new ScheduledTaskFinished($tarea, 0.0));

        expect(Context::getCurrent())->toBe($antes);
    });
})->group('RF-PD-15');

it('no deja el contexto colgado cuando la tarea programada no llega a emitir su final', function (): void {
    // La prueba del defecto. Sin `ScheduledTaskFinished`: el comando revienta, el
    // proceso muere, o el emisor solo conoce la primera mitad del par. Con
    // `startActive()` esto dejaba un `Scope` atado que reventaba en otra prueba.
    UnreachableCollector::around(function (): void {
        $antes = Context::getCurrent();

        (new ScheduledTaskSpans)->starting(new ScheduledTaskStarting(tareaProgramada()));

        expect(Context::getCurrent())->toBe($antes);
    });
})->group('RF-PD-15');
