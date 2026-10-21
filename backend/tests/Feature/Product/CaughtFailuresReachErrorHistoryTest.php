<?php

declare(strict_types=1);

use App\Modules\Product\Infrastructure\Capture\CaughtFailureHistory;
use App\Modules\Product\Infrastructure\Capture\ExecutionContext;
use App\Modules\Shared\Application\Port\CaughtFailureReporter;
use App\Modules\Shared\Application\Port\ErrorEventSink;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use App\Support\Database\DatabaseUnavailable;
use App\Support\Redis\RedisCircuitOpen;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\Product\InMemoryErrorEventSink;

/*
 * LOS FALLOS CAPTURADOS A PROPOSITO LLEGAN AL HISTORICO DE ERRORES (F4c-1,
 * RF-PD-15), POR EL MISMO FILTRO QUE LOS QUE SE ESCAPAN.
 *
 * El elemento de un lote, los trabajos de exportacion, la deteccion, la
 * reconciliacion y el resumen semanal capturan cualquier `Throwable` para que un
 * fallo no se lleve a los demas. `CaughtFailureReporter` los lleva a
 * `error_events` sin pasar por Monolog —ya dejan su apunte con la clase—, y
 * descarta lo mismo que `dontReportWhen` en `bootstrap/app.php`: la base
 * inalcanzable y el circuito de Redis abierto, que constan una vez al abrirse su
 * cortacircuitos y que en una caida escribirian una fila por elemento de cada
 * lote.
 */

beforeEach(function (): void {
    $sink = new InMemoryErrorEventSink;
    app()->instance(ErrorEventSink::class, $sink);
    app()->instance(InMemoryErrorEventSink::class, $sink);
    app()->make(ExecutionContext::class)->reset();

    // Fuera de una peticion, como el planificador que corre la deteccion.
    event(new CommandStarting('attendance:detect-anomalies', new ArrayInput([]), new NullOutput));
});

it('el puerto se resuelve al adaptador del historico', function (): void {
    expect(app(CaughtFailureReporter::class))->toBeInstanceOf(CaughtFailureHistory::class);
})->group('RF-PD-15');

it('guarda el fallo capturado con su clase y su origen', function (): void {
    app(CaughtFailureReporter::class)->report(new RuntimeException('La incidencia no se pudo abrir'));

    $error = app(InMemoryErrorEventSink::class)->only();

    expect($error->source)->toBe(ErrorSource::Console)
        ->and($error->exceptionClass)->toBe(RuntimeException::class);
})->group('RF-PD-15');

it('no escribe en el log tecnico: ese apunte ya lo deja quien captura', function (): void {
    $apuntes = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $apunte) use (&$apuntes): void {
        $apuntes[] = $apunte->message;
    });

    app(CaughtFailureReporter::class)->report(new RuntimeException('Maria Gonzalez no pudo fichar'));

    expect($apuntes)->toBe([]);
})->group('RF-PD-15', 'RS-05');

it('descarta las averias que ya constan al abrirse su cortacircuitos', function (Throwable $fallo): void {
    app(CaughtFailureReporter::class)->report($fallo);

    expect(app(InMemoryErrorEventSink::class)->isEmpty())->toBeTrue();
})->with([
    'circuito de Redis abierto' => [fn (): Throwable => RedisCircuitOpen::make()],
    'Redis abierto dentro de otra excepcion' => [fn (): Throwable => new RuntimeException('envuelta', 0, RedisCircuitOpen::make())],
    'base inalcanzable' => [fn (): Throwable => DatabaseUnavailable::unreachable(5)],
    'base inalcanzable en una QueryException' => [fn (): Throwable => new QueryException('pgsql', 'select 1', [], DatabaseUnavailable::circuitOpen(5))],
])->group('RF-PD-15', 'RNF-D-03');
