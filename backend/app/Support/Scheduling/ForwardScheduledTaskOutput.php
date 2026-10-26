<?php

declare(strict_types=1);

namespace App\Support\Scheduling;

use Illuminate\Console\Scheduling\Event as ScheduledTask;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Lo que hace {@see ScheduledTaskOutput} cuando la tarea termina: leer su
 * salida, borrarla y escribirla en el log (RNF-D-02, regla dura 21).
 *
 * Corre en `schedule:finish`, con el codigo de salida ya puesto en la tarea, y
 * recibe el registrador por parametro para poder probarse sin framework
 * (`ForwardScheduledTaskOutputTest`, suite `Unit`).
 *
 * ## Que escribe
 *
 * - Con `toLog()`, una linea de log por linea no vacia de la salida, **como
 *   mensaje** (ahi es donde el saneador aplica la lista blanca por palabra) y
 *   con `event = scheduler.task_output`, el nombre de la tarea y el numero de
 *   linea en el contexto, en `info` si la tarea salio 0 y en `warning` si no
 *   (con `LOG_LEVEL=warning` la salida de un fallo no se pierde). Hasta {@see self::MAX_LINES} lineas y
 *   {@see self::MAX_BYTES} bytes: un comando que vuelca una tabla entera no
 *   inunda el log ni la memoria del proceso.
 * - `scheduler.task_finished` con el nombre, el codigo de salida, los bytes y
 *   las lineas que escribio, si se escribieron (`forwarded`) o solo se
 *   contaron (`summarized`) y si se recorto. `info` si salio 0 y `warning` si
 *   no: el `error` lo sigue poniendo `scheduler.command_failed` en las tareas
 *   cuyo codigo significa algo, y las alertas de Loki no cambian. **Salvo el
 *   exito silencioso** (codigo 0 y 0 bytes de salida), que no deja apunte: ya
 *   lo dicen las metricas y los spans de cada tarea, y la medida del WAL, que
 *   corre cada minuto, llenaria el log del contenedor de lineas iguales.
 * - Si leer la captura o escribir falla, `scheduler.task_output_failed` con
 *   la clase de la excepcion, y nunca lanza: detras van los demas callbacks
 *   de la tarea, entre ellos `scheduler.command_failed`.
 *
 * ## Que NO escribe
 *
 * El texto de la salida en el contexto. El contexto se sanea solo por patrones
 * (ADR-048) y un nombre sin comillas pasaria.
 */
final readonly class ForwardScheduledTaskOutput
{
    /** Techo de lineas que se reenvian por ejecucion. */
    public const int MAX_LINES = 100;

    /** Techo de bytes que se leen de la salida. */
    public const int MAX_BYTES = 65_536;

    /** Lo que se registra si el planificador no dejo codigo. Ver `LogScheduledCommandFailure`. */
    private const int UNKNOWN_EXIT_CODE = -1;

    public function __construct(
        private string $command,
        private ScheduledTask $task,
        private string $path,
        private bool $forwardLines,
    ) {}

    public function __invoke(LoggerInterface $logger): void
    {
        $exitCode = $this->task->exitCode ?? self::UNKNOWN_EXIT_CODE;

        // Nada de lo que pase aqui puede llegar a `Event::finish()`: un fallo
        // al leer la captura o al escribir la linea cortaria los callbacks que
        // van detras —el `scheduler.command_failed` de `onFailure()`— y la
        // tarea fallida no dejaria rastro. Se deja constancia de que el
        // reenvio fallo (solo la clase de la excepcion: su mensaje puede
        // llevar la ruta o el texto de la salida) y se borra la captura.
        try {
            $this->forward($logger, $exitCode);
        } catch (Throwable $exception) {
            $this->discard();

            try {
                $logger->warning('scheduler.task_output_failed', [
                    'command' => $this->command,
                    'exit_code' => $exitCode,
                    'exception_class' => $exception::class,
                ]);
            } catch (Throwable) {
                // Si ni esto se puede escribir, no hay a quien contarlo.
            }
        }
    }

    private function forward(LoggerInterface $logger, int $exitCode): void
    {
        [$bytes, $text] = $this->readAndDiscard();

        // Con la tarea en rojo, las lineas van en `warning` como el resumen:
        // con `LOG_LEVEL=warning` en produccion, en `info` se perderia
        // justamente la salida que explica el fallo.
        $level = $exitCode === 0 ? LogLevel::INFO : LogLevel::WARNING;

        $lines = $this->linesOf($text);
        $forwarded = $this->forwardLines ? \array_slice($lines, 0, self::MAX_LINES) : [];

        foreach ($forwarded as $index => $line) {
            $logger->log($level, $line, [
                'event' => 'scheduler.task_output',
                'command' => $this->command,
                'line' => $index + 1,
            ]);
        }

        // El exito silencioso no deja apunte: ya lo reflejan las metricas y
        // los spans, y `backup:wal-metrics` dejaria uno por minuto.
        if ($exitCode === 0 && $bytes === 0) {
            return;
        }

        $logger->log($level, 'scheduler.task_finished', [
            'command' => $this->command,
            'exit_code' => $exitCode,
            'output_bytes' => $bytes,
            'output_lines' => \count($lines),
            'output' => $this->forwardLines ? 'forwarded' : 'summarized',
            'truncated' => $bytes > self::MAX_BYTES || ($this->forwardLines && \count($forwarded) < \count($lines)),
        ]);
    }

    /**
     * El tamano del fichero y, como mucho, sus primeros {@see self::MAX_BYTES}
     * bytes. El fichero se borra siempre, se haya podido leer o no: lo que no
     * se escribe en el log no se queda en el disco.
     *
     * @return array{0: int, 1: string}
     */
    private function readAndDiscard(): array
    {
        if (! is_file($this->path)) {
            return [0, ''];
        }

        try {
            $bytes = (int) filesize($this->path);

            // Tambien con `summaryOnly()`: hace falta para contar las lineas.
            // Se lee la misma ventana para no cargar en memoria un volcado
            // enorme.
            $text = (string) file_get_contents($this->path, false, null, 0, self::MAX_BYTES);
        } finally {
            $this->discard();
        }

        return [$bytes, $text];
    }

    /**
     * Borra la captura si sigue ahi. Sin lanzar: lo llama tambien la ruta de
     * fallo.
     */
    private function discard(): void
    {
        try {
            if (is_file($this->path)) {
                unlink($this->path);
            }
        } catch (Throwable) {
            // Laravel convierte el aviso de `unlink()` en excepcion; aqui no
            // tiene a quien avisar y no debe cortar `finish()`. La siguiente
            // ejecucion sobrescribe el fichero.
        }
    }

    /**
     * Las lineas no vacias, sin los espacios de los extremos.
     *
     * @return list<string>
     */
    private function linesOf(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];

        return array_values(array_filter(
            array_map(trim(...), $lines),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
