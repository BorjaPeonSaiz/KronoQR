<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Capture;

use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledTask;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Donde esta el proceso ahora mismo (RF-PD-15, tarea 5.12, decision 2).
 *
 * ## Por que hace falta
 *
 * El historico de errores tiene que distinguir **cuatro origenes de servidor**
 * -`api`, `worker`, `scheduler`, `console`- y la excepcion no lo dice: un
 * `RuntimeException` es identico venga de un controlador o de la reconciliacion
 * nocturna. Lo unico que lo sabe es el propio proceso, y lo sabe **antes** de
 * fallar. Esta clase lo recuerda: unos pocos oyentes de eventos del framework
 * abren y cierran marcos, y {@see ServerErrorReporter} pregunta.
 *
 * La alternativa -un enganche distinto por origen: el manejador HTTP, el
 * `failed()` de la cola, el `onFailure()` del planificador- serian cuatro sitios
 * que mantener y cuatro formas de olvidarse de uno. Con un contexto y un unico
 * `reportable`, quien anada un quinto origen anade un oyente y nada mas.
 *
 * ## Tres huecos con nombre y no una pila
 *
 * Los marcos no se apilan: hay un hueco por cada clase de marco -trabajo, tarea
 * programada, comando- y quien entra pisa el anterior de su clase. Es lo que
 * evita la fuga que si tendria una pila: un trabajo que falla **no emite
 * `JobProcessed`**, asi que su marco no se cierra nunca (el informe llega
 * despues, y por eso tiene que seguir abierto); con una pila, cada trabajo
 * fallido dejaria un marco muerto debajo del siguiente. Con huecos, el
 * `JobProcessing` del siguiente lo sustituye y no hay nada que limpiar.
 *
 * ## Precedencia: el mas especifico manda
 *
 * `worker` > `scheduler` > `console` > `api`, y las dos primeras aristas son
 * deliberadas:
 *
 * - **`scheduler` gana a `console`** porque `schedule:run` ES un comando que
 *   lanza otros: sin esta regla, todo fallo del planificador se contaria como
 *   consola y la alerta de «tarea programada que falla en silencio» -el motivo
 *   por el que `scheduler` es siempre `critical`- no distinguiria nada.
 * - **`worker` gana a `console`** por lo mismo con `queue:work`.
 *
 * ## Singleton, y con estado a proposito
 *
 * Es un objeto por proceso: un worker de Horizon vive horas y encadena miles de
 * trabajos con el mismo contenedor. Por eso {@see self::reset()} existe: las
 * pruebas necesitan volver al estado inicial sin reconstruir la aplicacion.
 */
final class ExecutionContext
{
    /** Prefijo de las rutas de fichaje: cualquier fallo en ellas es `critical` (decision 3). */
    public const string CLOCKING_ROUTE_PREFIX = '/api/v1/scan';

    private ?ExecutionFrame $job = null;

    private ?ExecutionFrame $scheduledTask = null;

    private ?ExecutionFrame $command = null;

    /**
     * El marco vigente, o `null` si no hay ninguno -que es el caso normal de una
     * peticion HTTP, y lo resuelve {@see ServerErrorReporter} mirando la peticion.
     */
    public function current(): ?ExecutionFrame
    {
        return $this->job ?? $this->scheduledTask ?? $this->command;
    }

    public function reset(): void
    {
        $this->job = null;
        $this->scheduledTask = null;
        $this->command = null;
    }

    /**
     * Los oyentes, en el unico sitio donde se puede leer entero cual abre y cual
     * cierra. Los registra `ProductServiceProvider::registerErrorCapture()`.
     *
     * @return array<class-string, callable(object): void>
     */
    public function listeners(): array
    {
        return [
            JobProcessing::class => function (object $event): void {
                $this->job = $this->jobFrame($event, critical: false);
            },
            // El unico que cierra el marco de un trabajo. `JobExceptionOccurred`
            // NO aparece aqui, y es la parte delicada: el informe a Monolog llega
            // DESPUES (`Worker::runJob()` atrapa, reporta y sigue), asi que
            // cerrar ahi dejaria sin origen justo al error que se quiere captar.
            JobProcessed::class => function (): void {
                $this->job = null;
            },
            // Agoto sus intentos: nadie lo esta mirando y el resultado ya no
            // llegara (decision 3). Se dispara ANTES del informe, de modo que la
            // severidad ya esta puesta cuando el error se persiste.
            JobFailed::class => function (object $event): void {
                $this->job = $this->jobFrame($event, critical: true);
            },
            ScheduledTaskStarting::class => function (object $event): void {
                $this->scheduledTask = $this->scheduledTaskFrame($event);
            },
            ScheduledTaskFinished::class => function (): void {
                $this->scheduledTask = null;
            },
            // Vuelve a ABRIR el marco, no lo conserva: `ScheduleRunCommand`
            // despacha `ScheduledTaskFinished` -que lo cerro- antes de lanzar por
            // codigo de salida distinto de cero, y solo despues
            // `ScheduledTaskFailed` y el informe.
            ScheduledTaskFailed::class => function (object $event): void {
                $this->scheduledTask = $this->scheduledTaskFrame($event);
            },
            CommandStarting::class => function (object $event): void {
                $this->command = $this->commandFrame($event);
            },
            CommandFinished::class => function (): void {
                $this->command = null;
            },
        ];
    }

    private function jobFrame(object $event, bool $critical): ExecutionFrame
    {
        $job = property_exists($event, 'job') ? $event->job : null;

        if (! $job instanceof Job) {
            return new ExecutionFrame(ErrorSource::Worker, critical: $critical);
        }

        return new ExecutionFrame(
            ErrorSource::Worker,
            [
                'job' => $job->resolveName(),
                'queue' => $job->getQueue(),
                'attempts' => $job->attempts(),
            ],
            $critical,
        );
    }

    /**
     * Toda tarea del planificador es `critical`: la reconciliacion nocturna o la
     * purga que fallan **cuando no hay nadie mirando** son justo lo que la alerta
     * del doc 01 §9.3 tiene que sacar a la luz (decision 3).
     */
    private function scheduledTaskFrame(object $event): ExecutionFrame
    {
        $task = property_exists($event, 'task') ? $event->task : null;

        return new ExecutionFrame(
            ErrorSource::Scheduler,
            ['command' => $task instanceof ScheduledTask ? $this->nameOf($task) : 'unknown'],
            critical: true,
        );
    }

    private function commandFrame(object $event): ExecutionFrame
    {
        $command = property_exists($event, 'command') ? $event->command : null;

        return new ExecutionFrame(
            ErrorSource::Console,
            ['command' => \is_string($command) && $command !== '' ? $command : 'unknown'],
        );
    }

    /**
     * El nombre de una tarea programada, en la forma en que una persona la
     * reconoce: `compliance:apply-retention`, y no la linea de ordenes entera con
     * el binario de PHP, el `artisan` y la redireccion.
     *
     * `Event::$command` la trae completa porque el planificador lanza cada comando
     * en un proceso aparte. Sin recortarla, el saneado del servidor -que convierte
     * todo lo entrecomillado en marcador- dejaria en la tabla algo que ya no
     * identifica la tarea.
     */
    private function nameOf(ScheduledTask $task): string
    {
        $description = $task->description;

        if (\is_string($description) && $description !== '') {
            return $description;
        }

        $command = $task->command;

        if (! \is_string($command) || $command === '') {
            return 'closure';
        }

        return preg_match('/artisan[\'"]?\s+[\'"]?([A-Za-z0-9:_.-]+)/', $command, $matches) === 1
            ? $matches[1]
            : $command;
    }
}
