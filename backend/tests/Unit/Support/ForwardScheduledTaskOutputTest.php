<?php

declare(strict_types=1);

use App\Support\Scheduling\ForwardScheduledTaskOutput;
use App\Support\Scheduling\ScheduledTaskOutput;
use Illuminate\Console\Scheduling\Event as ScheduledTask;
use Illuminate\Console\Scheduling\EventMutex;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Tests\Support\Observability\RecordingLogger;

/*
 * Lo que llega al log cuando una tarea en segundo plano termina (V4-BE-1,
 * RNF-D-02, regla dura 21).
 *
 * QUE SE FIJA AQUI. Que cada linea de la salida va como MENSAJE —donde el
 * saneador de Monolog aplica la lista blanca por palabra— y nunca en el
 * contexto; que el resumen lleva el codigo de salida y los recuentos; que
 * `summaryOnly()` no reenvia ni una linea; que hay techo; y que el fichero de
 * captura desaparece siempre. Que el canal real sanea lo comprueba
 * `ScheduledTaskOutputReachesTheContainerLogTest` (Feature).
 *
 * Es unitaria: el registrador entra por parametro.
 */

function forwardOutputTask(string $command, ?int $exitCode): ScheduledTask
{
    $mutex = new class implements EventMutex
    {
        public function create(ScheduledTask $event): bool
        {
            return true;
        }

        public function exists(ScheduledTask $event): bool
        {
            return false;
        }

        public function forget(ScheduledTask $event): void {}
    };

    $task = new ScheduledTask($mutex, $command);
    $task->exitCode = $exitCode;

    return $task;
}

/**
 * Un fichero de captura con el contenido dado, como lo deja el shell.
 */
function forwardOutputCapture(string $contents): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'kq-forward-');
    file_put_contents($path, $contents);

    return $path;
}

it('reenvia cada linea como mensaje y cierra con el resumen', function (): void {
    $path = forwardOutputCapture("Copia completada\n\n  Volcado verificado  \n");
    $logger = new RecordingLogger;

    (new ForwardScheduledTaskOutput('backup:run --mode=logical', forwardOutputTask('x', 0), $path, true))($logger);

    expect($logger->lines)->toHaveCount(3)
        ->and($logger->lines[0])->toBe([
            'level' => 'info',
            'message' => 'Copia completada',
            'context' => ['event' => 'scheduler.task_output', 'command' => 'backup:run --mode=logical', 'line' => 1],
        ])
        ->and($logger->lines[1]['message'])->toBe('Volcado verificado')
        ->and($logger->lines[1]['context']['line'])->toBe(2)
        ->and($logger->lines[2])->toBe([
            'level' => 'info',
            'message' => 'scheduler.task_finished',
            'context' => [
                'command' => 'backup:run --mode=logical',
                'exit_code' => 0,
                'output_bytes' => 41,
                'output_lines' => 2,
                'output' => 'forwarded',
                'truncated' => false,
            ],
        ])
        ->and(is_file($path))->toBeFalse();
})->group('RNF-D-02', 'RL-08');

it('nunca pone el texto de la salida en el contexto', function (): void {
    // El contexto se sanea solo por patrones (ADR-048, D3): un nombre sin
    // comillas ahi pasaria. Solo el mensaje pasa por la lista blanca.
    $path = forwardOutputCapture("Aviso enviado a Rosa Ficticiana\n");
    $logger = new RecordingLogger;

    (new ForwardScheduledTaskOutput('reporting:weekly-summary', forwardOutputTask('x', 0), $path, true))($logger);

    foreach ($logger->lines as $line) {
        expect((string) json_encode($line['context']))->not->toContain('Ficticiana');
    }
})->group('RNF-D-02', 'RL-08');

it('con summaryOnly solo escribe el codigo de salida y los recuentos', function (): void {
    $path = forwardOutputCapture("employees 1200\nshift_entries 834112\n");
    $logger = new RecordingLogger;

    (new ForwardScheduledTaskOutput('compliance:apply-retention --dry-run', forwardOutputTask('x', 2), $path, false))($logger);

    expect($logger->lines)->toHaveCount(1)
        ->and($logger->first()['level'])->toBe('warning')
        ->and($logger->first()['message'])->toBe('scheduler.task_finished')
        ->and($logger->first()['context'])->toMatchArray([
            'exit_code' => 2,
            'output_lines' => 2,
            'output' => 'summarized',
        ])
        ->and((string) json_encode($logger->lines))->not->toContain('834112')
        ->and(is_file($path))->toBeFalse();
})->group('RNF-D-02', 'RL-08');

it('pone techo a las lineas reenviadas y lo dice', function (): void {
    $path = forwardOutputCapture(str_repeat("fila\n", ForwardScheduledTaskOutput::MAX_LINES + 5));
    $logger = new RecordingLogger;

    (new ForwardScheduledTaskOutput('credentials:status --quiet-table', forwardOutputTask('x', 0), $path, true))($logger);

    $resumen = $logger->lines[ForwardScheduledTaskOutput::MAX_LINES] ?? throw new RuntimeException('Falta el resumen.');

    expect($logger->lines)->toHaveCount(ForwardScheduledTaskOutput::MAX_LINES + 1)
        ->and($resumen['context']['output_lines'])->toBe(ForwardScheduledTaskOutput::MAX_LINES + 5)
        ->and($resumen['context']['truncated'])->toBeTrue();
})->group('RNF-D-02');

it('sin fichero de captura deja el resumen igualmente, con -1 si no hubo codigo', function (): void {
    $logger = new RecordingLogger;
    $missing = sys_get_temp_dir().'/kq-forward-no-existe-'.bin2hex(random_bytes(4));

    (new ForwardScheduledTaskOutput('product:telemetry --send', forwardOutputTask('x', null), $missing, true))($logger);

    expect($logger->lines)->toHaveCount(1)
        ->and($logger->first()['level'])->toBe('warning')
        ->and($logger->first()['context'])->toMatchArray(['exit_code' => -1, 'output_bytes' => 0, 'output_lines' => 0]);
})->group('RNF-D-02');

it('el exito silencioso no deja apunte, pero si borra la captura', function (bool $forwardLines): void {
    // Codigo 0 y 0 bytes: lo dicen las metricas y los spans. Sin esto,
    // `backup:wal-metrics` dejaria una linea por minuto en el log.
    $path = forwardOutputCapture('');
    $logger = new RecordingLogger;

    (new ForwardScheduledTaskOutput('backup:wal-metrics', forwardOutputTask('x', 0), $path, $forwardLines))($logger);

    expect($logger->lines)->toBe([])
        ->and(is_file($path))->toBeFalse();
})->with(['toLog' => true, 'summaryOnly' => false])->group('RNF-D-02');

it('con codigo distinto de cero deja el resumen aunque no haya salida', function (): void {
    $path = forwardOutputCapture('');
    $logger = new RecordingLogger;

    (new ForwardScheduledTaskOutput('backup:wal-metrics', forwardOutputTask('x', 1), $path, true))($logger);

    expect($logger->lines)->toHaveCount(1)
        ->and($logger->first()['level'])->toBe('warning')
        ->and($logger->first()['context'])->toMatchArray(['exit_code' => 1, 'output_bytes' => 0]);
})->group('RNF-D-02');

it('con salida y codigo cero deja el resumen', function (): void {
    $path = forwardOutputCapture("Copia completada\n");
    $logger = new RecordingLogger;

    (new ForwardScheduledTaskOutput('backup:run --mode=dump', forwardOutputTask('x', 0), $path, false))($logger);

    expect($logger->lines)->toHaveCount(1)
        ->and($logger->first()['level'])->toBe('info')
        ->and($logger->first()['message'])->toBe('scheduler.task_finished');
})->group('RNF-D-02');

it('con la tarea en rojo las lineas van en warning, no en info', function (): void {
    // Con LOG_LEVEL=warning en produccion, en info se perderia justo la salida
    // que explica el fallo.
    $path = forwardOutputCapture("pg_dump: error de conexion\n");
    $logger = new RecordingLogger;

    (new ForwardScheduledTaskOutput('backup:run --mode=dump', forwardOutputTask('x', 1), $path, true))($logger);

    expect(array_column($logger->lines, 'level'))->toBe(['warning', 'warning'])
        ->and($logger->lines[0]['context']['event'])->toBe('scheduler.task_output');
})->group('RNF-D-02');

/**
 * Un registrador que falla con todo salvo con el apunte del propio fallo.
 */
function forwardOutputFailingLogger(RecordingLogger $recorder, bool $evenTheFailure = false): LoggerInterface
{
    return new class($recorder, $evenTheFailure) extends AbstractLogger
    {
        public function __construct(private readonly RecordingLogger $recorder, private readonly bool $evenTheFailure) {}

        /**
         * @param  array<string, mixed>  $context
         */
        public function log(mixed $level, string|Stringable $message, array $context = []): void
        {
            if ($this->evenTheFailure || (string) $message !== 'scheduler.task_output_failed') {
                throw new RuntimeException('canal roto: Rosa Ficticiana');
            }

            $this->recorder->log($level, $message, $context);
        }
    };
}

it('si el reenvio falla no lanza, lo dice sin el mensaje y borra la captura', function (): void {
    // Un fallo aqui cortaria los callbacks que van detras en Event::finish(),
    // y con ellos scheduler.command_failed.
    $path = forwardOutputCapture("Copia completada\n");
    $logger = new RecordingLogger;

    (new ForwardScheduledTaskOutput('backup:run --mode=dump', forwardOutputTask('x', 2), $path, true))(forwardOutputFailingLogger($logger));

    expect($logger->lines)->toHaveCount(1)
        ->and($logger->first()['level'])->toBe('warning')
        ->and($logger->first()['context'])->toBe([
            'command' => 'backup:run --mode=dump',
            'exit_code' => 2,
            'exception_class' => RuntimeException::class,
        ])
        ->and((string) json_encode($logger->lines))->not->toContain('Ficticiana')
        ->and(is_file($path))->toBeFalse();
})->group('RNF-D-02', 'RL-08');

it('no lanza ni aunque no se pueda escribir nada', function (): void {
    $path = forwardOutputCapture("Copia completada\n");

    (new ForwardScheduledTaskOutput('backup:run --mode=dump', forwardOutputTask('x', 1), $path, true))(forwardOutputFailingLogger(new RecordingLogger, true));

    expect(is_file($path))->toBeFalse();
})->group('RNF-D-02');

it('nombra la tarea por lo que va detras de artisan, sin el binario ni las comillas', function (): void {
    $task = forwardOutputTask("'/usr/local/bin/php' 'artisan' backup:run --mode='logical'", null);

    expect(ScheduledTaskOutput::nameOf($task))->toBe('backup:run --mode=logical');
})->group('RNF-D-02');

it('da a cada tarea su propio fichero de captura en el temporal', function (): void {
    $diaria = forwardOutputTask("'php' 'artisan' backup:run --mode='logical'", null)->dailyAt('03:15');
    $semanal = forwardOutputTask("'php' 'artisan' backup:run --mode='physical'", null)->weeklyOn(0, '03:15');

    expect(ScheduledTaskOutput::capturePath($diaria))->not->toBe(ScheduledTaskOutput::capturePath($semanal))
        ->and(dirname(ScheduledTaskOutput::capturePath($diaria)))->toBe(rtrim(sys_get_temp_dir(), '/\\'))
        ->and(basename(ScheduledTaskOutput::capturePath($diaria)))->toStartWith(ScheduledTaskOutput::CAPTURE_PREFIX);
})->group('RNF-D-02');
