<?php

declare(strict_types=1);

namespace App\Support\Scheduling;

use Illuminate\Console\Scheduling\Event as ScheduledTask;
use Illuminate\Log\LogManager;

/**
 * El ayudante unico que decide adonde va la salida de una tarea programada en
 * segundo plano (RNF-D-02, regla dura 21).
 *
 * ## El hueco que cierra
 *
 * Laravel manda por defecto la salida de cada tarea a `/dev/null`. Con
 * `runInBackground()` ademas corre en otro proceso, y el de cierre
 * (`schedule:finish`) tambien escribe a `/dev/null`. Asi que de una copia
 * nocturna que fallaba con un mensaje de `pg_dump` claro quedaba, como mucho,
 * un codigo de salida —y solo en las nueve tareas con `onFailure()`—. Desde
 * aqui, toda tarea en segundo plano deja en el log del contenedor `scheduler`
 * (y de ahi en Loki, si la instalacion lo tiene) lo que escribio y como acabo.
 *
 * ## Como
 *
 * 1. La salida del comando va a un fichero propio de la tarea en el temporal
 *    del contenedor ({@see self::capturePath()}): la capa del contenedor, ni el
 *    volumen compartido ni la copia de seguridad (ADR-045, clase `disposable`).
 * 2. `then()` engancha {@see ForwardScheduledTaskOutput}, que Laravel ejecuta
 *    en `schedule:finish` con el codigo de salida ya puesto: lee el fichero, lo
 *    **borra** y escribe por el canal `scheduler` de `config/logging.php`.
 *
 * ## Datos personales: por el saneador, y en el sitio donde sanea de verdad
 *
 * La salida de una tarea es texto libre y puede llevar un dato personal en
 * algun mensaje. No hay lista de comandos «seguros» que mantener: **cada linea
 * se escribe como el MENSAJE del apunte**, y el mensaje pasa por la lista
 * blanca por palabra del saneador de Monolog (ADR-048), que quita un nombre
 * sin comillas. En el contexto solo van claves que escribe este codigo
 * (nombre de la tarea, numero de linea, recuentos), porque el contexto se sanea
 * solo por patrones y un nombre ahi pasaria. `ScheduledTaskOutputReachesTheContainerLogTest`
 * lo comprueba de extremo a extremo con el canal real.
 *
 * ## Dos modos, y quien elige es `routes/console.php`
 *
 * - {@see self::toLog()}: las lineas (saneadas y con techo) mas el resumen.
 *   Es lo normal.
 * - {@see self::summaryOnly()}: solo el resumen —codigo de salida y
 *   recuentos de lineas y bytes—, y el fichero se borra sin leerse entero.
 *   Para las tareas que ya habian decidido no dejar su salida en el log
 *   tecnico: la propuesta de purga (recuentos de filas por tabla del
 *   registro horario), las dos conciliaciones del registro (UUID de
 *   `shift_entries` e `#id` de `audit_log`) y las metricas de ausencias. La
 *   lista cerrada, con el motivo de cada una, vive en `ScheduledTaskOutputTest`.
 *
 * `ScheduledTaskOutputTest` (Architecture) exige que toda tarea con
 * `runInBackground()` pase por uno de los dos y que nadie declare la salida a
 * mano con `sendOutputTo()`, `appendOutputTo()` o `storeOutput()`.
 */
final class ScheduledTaskOutput
{
    /** El canal de `config/logging.php` por el que se escribe. */
    public const string CHANNEL = 'scheduler';

    /** Prefijo del fichero de captura, en el temporal del contenedor. */
    public const string CAPTURE_PREFIX = 'kronoqr-schedule-';

    /**
     * La salida, linea a linea por el saneador, y el resumen.
     */
    public static function toLog(ScheduledTask $task): ScheduledTask
    {
        return self::capture($task, true);
    }

    /**
     * Solo el codigo de salida y los recuentos; la salida no se escribe.
     */
    public static function summaryOnly(ScheduledTask $task): ScheduledTask
    {
        return self::capture($task, false);
    }

    /**
     * El fichero donde el comando deja su salida.
     *
     * Uno por tarea —el nombre sale del de su candado, que distingue
     * `backup:run --mode=…` diaria de la semanal— y en un directorio que
     * siempre existe: si la redireccion del shell apuntara a un directorio que
     * falta, el comando ni siquiera arrancaria. Se sobrescribe en cada
     * ejecucion y lo borra quien lo lee.
     */
    public static function capturePath(ScheduledTask $task): string
    {
        return rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR
            .self::CAPTURE_PREFIX.sha1($task->mutexName()).'.out';
    }

    /**
     * El nombre legible de la tarea: lo que va detras de `artisan`, sin las
     * comillas del escapado. Lleva los argumentos porque los escribe quien
     * programa la tarea, en el repositorio o en `config/`, nunca datos de
     * nadie.
     */
    public static function nameOf(ScheduledTask $task): string
    {
        $command = \is_string($task->command) ? $task->command : '';

        if (preg_match('/artisan[\'"]?\s+(.+)$/s', $command, $matches) === 1) {
            return trim(str_replace(['\'', '"'], '', $matches[1]));
        }

        $description = $task->description;

        return \is_string($description) && $description !== '' ? $description : 'closure';
    }

    private static function capture(ScheduledTask $task, bool $forwardLines): ScheduledTask
    {
        $path = self::capturePath($task);
        $forward = new ForwardScheduledTaskOutput(self::nameOf($task), $task, $path, $forwardLines);

        $task->sendOutputTo($path);

        // `then()` y no `onSuccess()` ni `onFailure()`: el resumen se escribe
        // si hubo salida o fallo, y el fichero se borra siempre.
        $task->then(static function (LogManager $logs) use ($forward): void {
            $forward($logs->channel(self::CHANNEL));
        });

        return $task;
    }
}
