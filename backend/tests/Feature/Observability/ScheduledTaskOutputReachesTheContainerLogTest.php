<?php

declare(strict_types=1);

use App\Modules\Product\Infrastructure\Logging\RedactPersonalDataProcessor;
use App\Support\Observability\Logging\ContainerLogChannel;
use App\Support\Observability\Logging\LogChannelStack;
use App\Support\Scheduling\ScheduledTaskOutput;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Monolog\Logger as Monolog;

/*
 * **LA SALIDA DE UNA TAREA EN SEGUNDO PLANO LLEGA AL LOG DEL CONTENEDOR, Y
 * SANEADA** (V4-BE-1, RNF-D-02, regla dura 21, RL-08).
 *
 * `ScheduledTaskOutputTest` (Architecture) lee `routes/console.php` como
 * texto. Aqui se comprueba lo que Laravel registra de verdad y el camino
 * entero: el `then()` del ayudante, el canal `scheduler` de
 * `config/logging.php`, el saneador de Monolog y el fichero en el que escribe,
 * que en produccion es `/proc/1/fd/2` y aqui un temporal.
 */

/**
 * Apunta el log del contenedor a un fichero temporal y devuelve su ruta. Sin
 * Loki: la prueba lee lo que llega al contenedor.
 */
function scheduledTaskOutputContainerLog(): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'kq-scheduler-log-');

    config([
        'logging.channels.'.LogChannelStack::SCHEDULER_PRIMARY.'.stream' => $path,
        'logging.channels.'.ScheduledTaskOutput::CHANNEL.'.channels' => [LogChannelStack::SCHEDULER_PRIMARY],
    ]);
    Log::forgetChannel(LogChannelStack::SCHEDULER_PRIMARY);
    Log::forgetChannel(ScheduledTaskOutput::CHANNEL);

    return $path;
}

/**
 * Las lineas JSON escritas en el log del contenedor.
 *
 * @return list<array<string, mixed>>
 */
function scheduledTaskOutputLines(string $path): array
{
    $lines = [];

    foreach (array_filter(explode("\n", (string) file_get_contents($path))) as $line) {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $lines[] = $decoded;
    }

    return $lines;
}

it('toda tarea registrada en segundo plano tiene su salida capturada por el ayudante', function (): void {
    resolve(Kernel::class)->bootstrap();

    $background = array_values(array_filter(
        resolve(Schedule::class)->events(),
        static fn ($event): bool => $event->runInBackground,
    ));

    expect(count($background))->toBeGreaterThan(10);

    foreach ($background as $event) {
        expect($event->output)->toBe(
            ScheduledTaskOutput::capturePath($event),
            ScheduledTaskOutput::nameOf($event).' tira su salida a '.$event->output.'. Envuelvela en ScheduledTaskOutput.',
        );
    }

    // Una por tarea: dos tareas con el mismo fichero se pisarian la salida.
    $paths = array_map(static fn ($event): string => $event->output, $background);
    expect(array_unique($paths))->toHaveCount(count($paths));
})->group('RNF-D-02', 'RL-08');

it('lleva al log del contenedor la salida saneada y el resumen de la tarea', function (): void {
    $log = scheduledTaskOutputContainerLog();

    $task = ScheduledTaskOutput::toLog(resolve(Schedule::class)->command('reporting:weekly-summary')->runInBackground());
    file_put_contents($task->output, "Aviso enviado a Rosa Ficticiana\n");
    $task->exitCode = 1;

    $task->callAfterCallbacks(app());

    $lines = scheduledTaskOutputLines($log);
    $todo = (string) file_get_contents($log);

    expect($todo)->not->toContain('Rosa')
        ->and($todo)->not->toContain('Ficticiana')
        ->and($lines)->toHaveCount(2)
        ->and($lines[0]['message'])->toStartWith('Aviso enviado a')
        ->and($lines[0]['context'])->toMatchArray(['event' => 'scheduler.task_output', 'command' => 'reporting:weekly-summary'])
        ->and($lines[1]['message'])->toBe('scheduler.task_finished')
        ->and($lines[1]['level_name'])->toBe('WARNING')
        ->and($lines[1]['context'])->toMatchArray(['command' => 'reporting:weekly-summary', 'exit_code' => 1])
        ->and(is_file($task->output))->toBeFalse();

    unlink($log);
})->group('RNF-D-02', 'RL-08');

it('con summaryOnly el log del contenedor no lleva la salida', function (): void {
    $log = scheduledTaskOutputContainerLog();

    $task = ScheduledTaskOutput::summaryOnly(
        resolve(Schedule::class)->command('compliance:apply-retention', ['--dry-run'])->runInBackground(),
    );
    file_put_contents($task->output, "shift_entries 834112\n");
    $task->exitCode = 0;

    $task->callAfterCallbacks(app());

    expect((string) file_get_contents($log))->not->toContain('834112')
        ->and(scheduledTaskOutputLines($log))->toHaveCount(1)
        ->and(is_file($task->output))->toBeFalse();

    unlink($log);
})->group('RNF-D-02', 'RL-08');

it('el canal del planificador termina en el saneador, uno solo', function (): void {
    config(['logging.channels.'.ScheduledTaskOutput::CHANNEL.'.channels' => [LogChannelStack::SCHEDULER_PRIMARY]]);
    Log::forgetChannel(ScheduledTaskOutput::CHANNEL);

    $wrapper = Log::channel(ScheduledTaskOutput::CHANNEL);
    $monolog = $wrapper instanceof Logger ? $wrapper->getLogger() : null;

    expect($monolog)->toBeInstanceOf(Monolog::class);

    $processors = $monolog instanceof Monolog ? $monolog->getProcessors() : [];

    expect(end($processors))->toBeInstanceOf(RedactPersonalDataProcessor::class)
        ->and(array_filter($processors, static fn (mixed $p): bool => $p instanceof RedactPersonalDataProcessor))->toHaveCount(1);
})->group('RNF-D-02', 'RL-08');

it('sin acceso al log del contenedor escribe por la salida de error propia', function (): void {
    expect(ContainerLogChannel::stream('/no/existe/fd/2'))->toBe(ContainerLogChannel::FALLBACK)
        ->and(ContainerLogChannel::stream(null))->toBe(ContainerLogChannel::FALLBACK);
})->group('RNF-D-02');

it('el planificador escribe en el log del contenedor con la pila del producto', function (): void {
    expect(LogChannelStack::forScheduler(['stderr', 'loki']))->toBe([LogChannelStack::SCHEDULER_PRIMARY, 'loki'])
        ->and(LogChannelStack::forScheduler(['stderr']))->toBe([LogChannelStack::SCHEDULER_PRIMARY]);
})->group('RNF-D-02');
