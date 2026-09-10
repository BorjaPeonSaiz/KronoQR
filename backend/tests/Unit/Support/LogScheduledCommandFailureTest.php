<?php

declare(strict_types=1);

use App\Support\Scheduling\LogScheduledCommandFailure;
use Illuminate\Console\Scheduling\Event as ScheduledTask;
use Illuminate\Console\Scheduling\EventMutex;
use Tests\Support\Observability\RecordingLogger;

/*
 * El apunte `scheduler.command_failed` (doc 02 §8.2, tarea 3.2, paso 9).
 *
 * QUE SE FIJA AQUI. Que la linea lleva el nombre de la tarea y su codigo de
 * salida, y que **no lleva la salida del comando**. Lo segundo no es estilo: el
 * apunte va al canal por defecto —Monolog, y de ahi a Loki y al paquete de
 * diagnostico (ADR-020)—, y la salida de `compliance:apply-retention --dry-run`
 * son recuentos de filas por tabla del registro horario del cliente (regla dura
 * 21).
 *
 * Es unitaria y no toca el contenedor: el registrador entra por parametro.
 */

/** Una tarea programada con su codigo de salida ya puesto, como la deja `finish()`. */
function tareaConCodigoDeSalida(string $command, ?int $exitCode): ScheduledTask
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

it('registra el nombre de la tarea y su codigo de salida', function (): void {
    $logger = new RecordingLogger;

    (new LogScheduledCommandFailure(
        'attendance:reconcile',
        tareaConCodigoDeSalida("php 'artisan' attendance:reconcile", 1),
    ))($logger);

    expect($logger->lines)->toHaveCount(1)
        ->and($logger->first()['message'])->toBe('scheduler.command_failed')
        ->and($logger->first()['context'])->toBe([
            'command' => 'attendance:reconcile',
            'exit_code' => 1,
        ]);
})->group('RF-PR-02', 'RF-PR-01');

it('escribe el apunte en nivel error, que es el que lo hace visible', function (): void {
    // EL NIVEL NO ES DECORACION. `LOG_LEVEL` de serie es `warning` en el
    // servidor del cliente: con `info` o `debug`, esta linea no llega a Loki, no
    // entra en `error_events` y no aparece en el paquete de diagnostico — y la
    // reconciliacion que lleva tres noches muriendo se ve exactamente igual que
    // una que va bien, que es el hueco que este apunte existe para cerrar.
    //
    // `error` y no `critical`: el fallo de una tarea nocturna se atiende por la
    // mañana; la alerta que despierta a alguien la disparan las metricas
    // (`projection_reconciliation_last_failures`), no esta linea.
    $logger = new RecordingLogger;

    (new LogScheduledCommandFailure(
        'attendance:reconcile',
        tareaConCodigoDeSalida("php 'artisan' attendance:reconcile", 1),
    ))($logger);

    expect($logger->first()['level'])->toBe('error');
})->group('RF-PR-02', 'RF-PD-15');

it('no escribe la salida del comando ni ninguna otra clave', function (): void {
    // La linea de la retencion es el caso que lo justifica: su salida son
    // recuentos por tabla del registro horario del cliente, y este apunte viaja
    // al fabricante dentro del paquete de diagnostico (regla dura 21).
    $logger = new RecordingLogger;

    (new LogScheduledCommandFailure(
        'compliance:apply-retention',
        tareaConCodigoDeSalida("php 'artisan' compliance:apply-retention --dry-run", 2),
    ))($logger);

    expect(array_keys($logger->first()['context']))->toBe(['command', 'exit_code'])
        ->and($logger->first()['context']['exit_code'])->toBe(2);
})->group('RL-11');

it('no finge un cero cuando el planificador no dejo codigo', function (): void {
    // `finish()` siempre lo asigna antes de llamar a los callbacks, asi que esto
    // no deberia ocurrir. Vale `-1` y no `1` a proposito: un cero seria mentira
    // —el callback solo corre cuando la tarea fallo— y un uno se confundiria con
    // un fallo real del comando.
    $logger = new RecordingLogger;

    (new LogScheduledCommandFailure('attendance:detect-incidents', tareaConCodigoDeSalida('php artisan x', null)))($logger);

    expect($logger->first()['context']['exit_code'])->toBe(-1);
})->group('RF-PR-01');

it('entrega a onFailure un cierre que ya conoce su tarea', function (): void {
    // `Event::onFailure()` exige un `Closure` y lo invoca con `Container::call()`,
    // que resuelve los parametros por tipo: el contenedor no sabe construir el
    // `Event` en curso, asi que el codigo de salida solo se puede leer de la
    // instancia que ya se tiene al programar. De ahi la factoria, y de ahi que
    // `routes/console.php` guarde la tarea en una variable.
    $logger = new RecordingLogger;
    $cierre = LogScheduledCommandFailure::of('attendance:reconcile', tareaConCodigoDeSalida('php artisan x', 3));

    $cierre($logger);

    expect($logger->first()['context'])->toBe([
        'command' => 'attendance:reconcile',
        'exit_code' => 3,
    ]);
})->group('RF-PR-02');
