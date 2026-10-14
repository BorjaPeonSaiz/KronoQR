<?php

declare(strict_types=1);

use App\Support\Scheduling\ForwardScheduledTaskOutput;
use App\Support\Scheduling\ScheduledTaskOutput;
use Illuminate\Console\Scheduling\Event as ScheduledTask;
use Illuminate\Console\Scheduling\EventMutex;
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
