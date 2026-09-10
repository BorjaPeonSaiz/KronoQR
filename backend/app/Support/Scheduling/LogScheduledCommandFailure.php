<?php

declare(strict_types=1);

namespace App\Support\Scheduling;

use Closure;
use Illuminate\Console\Scheduling\Event as ScheduledTask;
use Psr\Log\LoggerInterface;

/**
 * El apunte `scheduler.command_failed` de una tarea programada que termina con
 * codigo distinto de cero (doc 02 §8.2, tarea 3.2, paso 9).
 *
 * ## El hueco que cierra
 *
 * `attendance:reconcile` sale 1 ante cualquier divergencia y
 * `attendance:detect-incidents` sale 1 cuando un hallazgo no se pudo convertir
 * en incidencia. Hasta esta tarea ese codigo **no llegaba a ninguna parte**: las
 * tres tareas de madrugada usan `runInBackground()`, y `ScheduleRunCommand` solo
 * convierte el codigo de salida en excepcion cuando la tarea corre **en primer
 * plano** (`$event->exitCode != 0 && ! $event->runInBackground`). En segundo
 * plano el desenlace llega por `schedule:finish`, que se limita a llamar a
 * `Event::finish()`. Sin excepcion no hay `ScheduledTaskFailed`, sin
 * `ScheduledTaskFailed` no hay marco de captacion abierto y sin marco no hay
 * fila en `error_events`: un comando que empieza a fallar todas las noches se
 * veia exactamente igual que uno que va bien.
 *
 * Con `runInBackground()`, `Event::finish()` ejecuta igualmente los *callbacks*
 * de `then()` —y `onFailure()` es uno de ellos, con la guarda de codigo distinto
 * de cero—, asi que esto funciona con la programacion que ya existe y sin
 * quitarle el segundo plano a nadie.
 *
 * ## Lo que NO escribe: la salida del comando
 *
 * Nunca. `Event::onFailureWithOutput()` existe y aqui esta descartado a
 * proposito: la salida de `compliance:apply-retention --dry-run` son recuentos
 * por tabla del registro horario del cliente, y este apunte va al canal por
 * defecto, o sea a Loki y de ahi al paquete de diagnostico (ADR-020, regla dura
 * 21). Ademas `onFailureWithOutput()` obliga a capturar la salida en un fichero
 * de `storage/logs`, que es otra copia de lo mismo en el disco del cliente.
 *
 * Se escriben dos claves y ninguna mas: `command` —el nombre de la tarea, no la
 * linea de ordenes con el binario de PHP— y `exit_code`. Con eso, «¿que noches
 * fallo la reconciliacion?» es una consulta de Loki de una linea.
 *
 * ## Esto no sustituye a la metrica, la acompaña
 *
 * La alerta la disparan `projection_reconciliation_last_failures` e
 * `incident_detection_last_failures`, que son las que sobreviven a un reinicio y
 * las que Prometheus evalua. Esta linea es lo que se lee **despues** de que la
 * alerta suene, para saber que noche y con que codigo. Una tarea que no llega a
 * arrancar —el contenedor caido, la imagen sin el comando— no escribe metrica
 * ninguna y si escribe esta linea, que es el otro motivo de que existan las dos.
 *
 * ## Por que hace falta el `Event` y no basta con el nombre
 *
 * `Event::onFailure()` acepta un `Closure` y lo invoca con
 * `Container::call()`, que resuelve sus parametros por tipo: el contenedor no
 * sabe construir el `Event` en curso, asi que el codigo de salida solo se puede
 * leer de la instancia que ya se tiene al programar la tarea. De ahi
 * {@see self::of()} y de ahi que `routes/console.php` guarde la tarea en una
 * variable antes de encadenar.
 */
final readonly class LogScheduledCommandFailure
{
    /**
     * Lo que se registra cuando el planificador no dejo codigo. No deberia
     * ocurrir —`finish()` siempre lo asigna antes de llamar a los *callbacks*—,
     * y por eso vale `-1` y no `1`: un cero seria mentira y un uno se
     * confundiria con un fallo real del comando.
     */
    private const int UNKNOWN_EXIT_CODE = -1;

    public function __construct(
        private string $command,
        private ScheduledTask $task,
    ) {}

    /**
     * El cierre que `Event::onFailure()` exige, ya atado a su tarea.
     *
     * @param  string  $command  Nombre de la tarea tal como se programa, `attendance:reconcile`.
     */
    public static function of(string $command, ScheduledTask $task): Closure
    {
        return Closure::fromCallable(new self($command, $task));
    }

    public function __invoke(LoggerInterface $logger): void
    {
        $logger->error('scheduler.command_failed', [
            'command' => $this->command,
            'exit_code' => $this->task->exitCode ?? self::UNKNOWN_EXIT_CODE,
        ]);
    }
}
