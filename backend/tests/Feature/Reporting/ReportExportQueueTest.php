<?php

declare(strict_types=1);

use App\Modules\Reporting\Infrastructure\Job\GenerateReportExportJob;

/*
 * El trabajo de un informe en diferido no lo puede recoger otro proceso a mitad
 * (2.3.0, revision de seguridad del bloque 14).
 *
 * Con el `retry_after` de 90 s de la conexion normal y un trabajo de hasta 30
 * minutos, Horizon volvia a reservarlo a los 90 s: con `$tries = 1` el segundo
 * lo daba por fallido, marcaba la fila y borraba el fichero mientras el primero
 * escribia. Lo que se fija aqui, para cada *driver* con el que se instala el
 * producto: `retry_after` de la conexion del trabajo > techo del trabajo, y el
 * `timeout` del supervisor de Horizon entre los dos.
 */

/**
 * `config/queue.php` evaluado como lo veria una instalacion con ese driver.
 *
 * @return array<string, mixed>
 */
function colaDeInformesCon(string $driver): array
{
    $previous = getenv('QUEUE_CONNECTION');
    $previousEnv = $_ENV['QUEUE_CONNECTION'] ?? null;
    $previousServer = $_SERVER['QUEUE_CONNECTION'] ?? null;

    putenv('QUEUE_CONNECTION='.$driver);
    $_ENV['QUEUE_CONNECTION'] = $driver;
    $_SERVER['QUEUE_CONNECTION'] = $driver;

    try {
        /** @var array{connections: array<string, array<string, mixed>>} $config */
        $config = require base_path('config/queue.php');
    } finally {
        putenv($previous === false ? 'QUEUE_CONNECTION' : 'QUEUE_CONNECTION='.$previous);
        $_ENV['QUEUE_CONNECTION'] = $previousEnv;
        $_SERVER['QUEUE_CONNECTION'] = $previousServer;
    }

    return $config['connections'][GenerateReportExportJob::QUEUE_CONNECTION];
}

it('despacha el trabajo por su conexion y su cola propias', function (): void {
    $job = new GenerateReportExportJob('019a12b4-5c6d-7e8f-9012-3456789abcde');

    expect($job->connection)->toBe(GenerateReportExportJob::QUEUE_CONNECTION)
        ->and($job->queue)->toBe(GenerateReportExportJob::QUEUE)
        ->and($job->timeout)->toBe(GenerateReportExportJob::TIMEOUT_SECONDS)
        ->and($job->tries)->toBe(1);
})->group('RF-IN-06');

it('no vuelve a reservar el trabajo antes de su techo, con el driver que sea', function (string $driver): void {
    $conexion = colaDeInformesCon($driver);

    expect($conexion['driver'])->toBe($driver)
        ->and($conexion['queue'])->toBe(GenerateReportExportJob::QUEUE)
        ->and($conexion['retry_after'])->toBeGreaterThan(GenerateReportExportJob::TIMEOUT_SECONDS);
})->with(['redis', 'database'])->group('RF-IN-06');

it('el supervisor de Horizon de los informes corta entre el techo del trabajo y la nueva reserva', function (): void {
    /** @var array<string, mixed> $supervisor */
    $supervisor = config('horizon.defaults.supervisor-reports');
    $retryAfter = colaDeInformesCon('redis')['retry_after'];

    expect($supervisor['connection'])->toBe(GenerateReportExportJob::QUEUE_CONNECTION)
        ->and($supervisor['queue'])->toBe([GenerateReportExportJob::QUEUE])
        ->and($supervisor['timeout'])->toBeGreaterThanOrEqual(GenerateReportExportJob::TIMEOUT_SECONDS)
        ->and($supervisor['timeout'])->toBeLessThan($retryAfter)
        // Dos informes largos a la vez, como mucho, contra la base del fichaje.
        ->and($supervisor['maxProcesses'])->toBe(2)
        // Y el supervisor de siempre sigue atendiendo `default`.
        ->and(config('horizon.defaults.supervisor-1.queue'))->toBe(['default']);
})->group('RF-IN-06', 'RNF-P-02');
