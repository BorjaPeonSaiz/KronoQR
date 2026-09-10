<?php

declare(strict_types=1);

namespace App\Support\Observability\Tracing;

use App\Modules\Shared\Application\Support\SpanScope;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledTask;
use OpenTelemetry\API\Trace\SpanKind;
use Throwable;

/**
 * Un span por comando programado (doc 02 §8.1, decision 6 de la ficha 3.1).
 *
 * ## Por que el planificador tambien
 *
 * Porque ahi viven los actos que nadie mira hasta que fallan: la reconciliacion
 * nocturna de `daily_totals`, la verificacion de la cadena de auditoria, la
 * purga de retencion. Cuando una de esas tarda diez veces mas de lo normal, la
 * pregunta es cual de sus consultas se ha ido, y sin un span que las agrupe no
 * hay donde colgarlas: los spans de {@see DatabaseSpans} nacerian sueltos, uno
 * por consulta, sin nada que los relacione entre si.
 *
 * ## El nombre es el del comando, sin argumentos
 *
 * `'/usr/bin/php' 'artisan' compliance:apply-retention --since=2026-01-01` se
 * queda en `compliance:apply-retention`. Los argumentos pueden llevar una fecha,
 * una ruta o el UUID de alguien, y el nombre de un span es una etiqueta: misma
 * regla de cardinalidad y de minimizacion que en `RecordHttpMetrics`.
 *
 * ## Un mapa y no una propiedad
 *
 * El planificador ejecuta las tareas de una en una, pero un `finished` que no
 * casara con su `starting` dejaria el span anterior abierto para siempre. Con el
 * mapa indexado por el objeto de la tarea, cada cierre encuentra el suyo o no
 * cierra nada.
 *
 * ## El span NO SE ACTIVA, y eso es una correccion
 *
 * La primera version lo abria con `startActive()`, que ata un `Scope` al
 * contexto del proceso y solo lo suelta `end()` — es decir, solo si llega
 * `ScheduledTaskFinished`, `Skipped` o `Failed`. Un `ScheduledTaskStarting` sin
 * pareja —el proceso muere a mitad, o quien emite el evento solo conoce la
 * primera mitad del par— dejaba el `Scope` colgado, y entonces pasa algo peor
 * que perder una traza: el `DebugScope` de OpenTelemetry denuncia
 * «missing call to Scope::detach()» **al recolectar el objeto**, en un momento
 * cualquiera, y el manejador de errores de Laravel lo promociona a
 * `ErrorException` dentro de lo que estuviera corriendo en ese instante. En la
 * suite eso fueron 195 fallos en pruebas sin ninguna relacion con el
 * planificador; en produccion, un `ErrorException` en medio de otro comando.
 *
 * Lo que se pierde por no activar es que las consultas de una tarea **en primer
 * plano** cuelguen de su span. Se acepta: casi todas las tareas de
 * `routes/console.php` llevan `runInBackground()` y corren en OTRO proceso,
 * donde el scope de este nunca las habria alcanzado. Un span por comando con su
 * nombre, su expresion y su desenlace es lo que esta clase promete, y eso sigue
 * cumpliendose.
 */
final class ScheduledTaskSpans
{
    /** @var array<int, SpanScope> */
    private array $spans = [];

    public function starting(ScheduledTaskStarting $event): void
    {
        try {
            $this->spans[spl_object_id($event->task)] = SpanScope::start(
                'kronoqr.scheduler',
                'schedule '.$this->nameOf($event->task),
                SpanKind::KIND_INTERNAL,
                // La expresion cron, que la escribe `routes/console.php` y no
                // puede llevar datos de nadie.
                ['schedule.expression' => $event->task->expression],
            );
        } catch (Throwable) {
            // Un comando programado no deja de ejecutarse porque no se pueda medir.
        }
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        $this->close($event->task, ['schedule.outcome' => 'finished']);
    }

    public function skipped(ScheduledTaskSkipped $event): void
    {
        $this->close($event->task, ['schedule.outcome' => 'skipped']);
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->close($event->task, ['schedule.outcome' => 'failed']);
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    private function close(ScheduledTask $task, array $attributes): void
    {
        try {
            $key = spl_object_id($task);

            ($this->spans[$key] ?? null)?->end($attributes);

            unset($this->spans[$key]);
        } catch (Throwable) {
            // Ver el docblock de `starting()`.
        }
    }

    /**
     * @return non-empty-string
     */
    private function nameOf(ScheduledTask $task): string
    {
        $command = is_string($task->command) ? $task->command : '';

        if (preg_match('/artisan\'?\s+(\S+)/', $command, $matches) === 1) {
            return $matches[1];
        }

        // Una tarea de cierre no tiene comando; su descripcion la escribe quien
        // la programa y esta en el repositorio, asi que no puede llevar datos de
        // nadie.
        $description = $task->description;

        return is_string($description) && $description !== '' ? $description : 'closure';
    }
}
