<?php

declare(strict_types=1);

use App\Support\Observability\Logging\LokiHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Support\Observability\RecordingLokiTransport;

/*
 * El canal `loki` del log tecnico (doc 02 §8.1 y §8.2.1, decision 8 de la ficha
 * 3.1).
 *
 * ## Que se afirma aqui y por que es unitario
 *
 * Lo que puede romperse sin que nadie se entere es el **formato** —Loki rechaza
 * un envio mal compuesto con un `400` que nadie mira—, la **agrupacion** —un
 * `POST` por linea multiplicaria los viajes a la red del camino de fichaje— y,
 * sobre todo, que **un fallo no se propague**: la regla dura 19 dice que el
 * quiosco nunca bloquea al empleado, y un handler de log que lanza convierte un
 * fichaje correcto en un `500` por no poder guardar una linea.
 *
 * Nada de eso necesita un Loki de verdad ni el contenedor de servicios: necesita
 * un transporte que recuerde lo que le pasaron. El envio real por HTTP es una
 * linea y su unica logica —tiempos y `try/catch`— esta en `HttpLokiTransport`.
 */

/**
 * Un registro ya formateado, como llega a `write()` desde
 * `AbstractProcessingHandler`.
 */
function registroDeLog(string $message, Level $level = Level::Info, string $when = '2026-09-10 06:00:00.123456'): LogRecord
{
    return new LogRecord(
        new DateTimeImmutable($when),
        'testing',
        $level,
        $message,
        ['trace_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90'],
    );
}

function manejadorDeLoki(RecordingLokiTransport $transport, int $maxRecords = 1000): LokiHandler
{
    return new LokiHandler(
        $transport,
        'http://loki:3100',
        'kronoqr-api',
        'testing',
        1.0,
        $maxRecords,
    );
}

it('envia un solo POST con todo lo acumulado y con la forma que Loki exige', function (): void {
    $transport = RecordingLokiTransport::accepting();
    $handler = manejadorDeLoki($transport);

    $handler->handle(registroDeLog('attendance.scan_processed'));
    $handler->handle(registroDeLog('attendance.batch_synchronised'));

    // Antes de vaciar no se ha tocado la red: el envio ocurre al terminar la
    // peticion, despues de que el cliente tenga su respuesta.
    expect($transport->pushes)->toBe([])
        ->and($handler->pending())->toBe(2);

    $handler->flush();

    expect($transport->pushes)->toHaveCount(1)
        ->and($transport->pushes[0]['url'])->toBe('http://loki:3100/loki/api/v1/push');

    $payload = $transport->lastPayload();

    expect($payload['streams'])->toHaveCount(1)
        ->and($payload['streams'][0]['stream'])->toBe([
            'service' => 'kronoqr-api',
            'level' => 'info',
            'environment' => 'testing',
        ])
        ->and($payload['streams'][0]['values'])->toHaveCount(2);

    // Nanosegundos como cadena, con los microsegundos intactos: es lo que ordena
    // dos lineas de la misma peticion.
    [$timestamp, $line] = $payload['streams'][0]['values'][0];

    expect($timestamp)->toMatch('/^\d{19}$/')
        ->and($timestamp)->toEndWith('123456000')
        ->and($line)->toContain('attendance.scan_processed')
        // La linea es JSON, que es lo que hace consultable el log con `| json`.
        ->and(json_decode($line, true))->toBeArray();
})->group('RF-PD-15', 'RL-08');

it('agrupa por nivel, que es la unica etiqueta que varia', function (): void {
    $transport = RecordingLokiTransport::accepting();
    $handler = manejadorDeLoki($transport);

    $handler->handle(registroDeLog('attendance.scan_processed'));
    $handler->handle(registroDeLog('attendance.scan_rejected', Level::Warning));
    $handler->handle(registroDeLog('otra cosa', Level::Warning));

    $handler->flush();

    $streams = $transport->lastPayload()['streams'];

    expect($transport->pushes)->toHaveCount(1)
        ->and($streams)->toHaveCount(2);

    $porNivel = [];

    foreach ($streams as $stream) {
        $porNivel[$stream['stream']['level']] = count($stream['values']);
    }

    expect($porNivel)->toBe(['info' => 1, 'warning' => 2]);
})->group('RF-PD-15');

it('no usa ningun identificador como etiqueta de Loki', function (): void {
    /*
     * La decision que decide si Loki aguanta o se cae. Cada combinacion de
     * etiquetas es un flujo con su indice: un `trace_id` por etiqueta crea un
     * flujo por peticion. Ademas escribiria un directorio de identificadores en
     * el indice, que es lo que la regla dura 21 evita en el resto del sistema.
     *
     * El `trace_id` **si** esta, dentro de la linea, que es donde se busca con
     * `| json | trace_id="..."`.
     */
    $transport = RecordingLokiTransport::accepting();
    $handler = manejadorDeLoki($transport);

    $handler->handle(registroDeLog('attendance.scan_processed'));
    $handler->flush();

    $stream = $transport->lastPayload()['streams'][0];

    expect(array_keys($stream['stream']))->toBe(['service', 'level', 'environment'])
        ->and($stream['values'][0][1])->toContain('a1b2c3d4e5f60718293a4b5c6d7e8f90');
})->group('RF-PD-15', 'RL-08');

it('envia con un tiempo maximo de un segundo', function (): void {
    // Sin techo, un colector que acepta la conexion y no contesta se queda con el
    // proceso de PHP-FPM que hace falta para el siguiente fichaje.
    $transport = RecordingLokiTransport::accepting();

    $handler = manejadorDeLoki($transport);
    $handler->handle(registroDeLog('attendance.scan_processed'));
    $handler->flush();

    expect($transport->pushes[0]['timeout'])->toBe(1.0);
})->group('RF-PD-15');

it('no lanza cuando Loki rechaza el envio ni cuando revienta', function (): void {
    foreach ([RecordingLokiTransport::rejecting(), RecordingLokiTransport::exploding()] as $transport) {
        $handler = manejadorDeLoki($transport);
        $handler->handle(registroDeLog('attendance.scan_processed'));

        $handler->flush();

        expect($handler->failures())->toBe(1)
            // Y el buffer queda limpio: reintentar en la siguiente peticion
            // acumularia lineas viejas hasta quedarse sin memoria.
            ->and($handler->pending())->toBe(0);
    }
})->group('RF-PD-15');

it('no vuelve a enviar si no hay nada pendiente', function (): void {
    $transport = RecordingLokiTransport::accepting();
    $handler = manejadorDeLoki($transport);

    $handler->flush();
    $handler->handle(registroDeLog('attendance.scan_processed'));
    $handler->flush();
    $handler->flush();

    expect($transport->pushes)->toHaveCount(1);
})->group('RF-PD-15');

it('descarta y cuenta a partir del techo del buffer, en vez de quedarse sin memoria', function (): void {
    // Un comando que escribe cien mil lineas no puede tumbar el proceso por culpa
    // del canal de log.
    $transport = RecordingLokiTransport::accepting();
    $handler = manejadorDeLoki($transport, maxRecords: 2);

    $handler->handle(registroDeLog('una'));
    $handler->handle(registroDeLog('dos'));
    $handler->handle(registroDeLog('tres'));

    $handler->flush();

    expect($handler->dropped())->toBe(1)
        ->and($transport->lastPayload()['streams'][0]['values'])->toHaveCount(2);
})->group('RF-PD-15');

it('vacia lo pendiente al cerrarse', function (): void {
    // `close()` es lo que llama Monolog al terminar el proceso: es la ultima red
    // por si nadie hubiera llamado a `flush()`.
    $transport = RecordingLokiTransport::accepting();
    $handler = manejadorDeLoki($transport);

    $handler->handle(registroDeLog('attendance.scan_processed'));
    $handler->close();

    expect($transport->pushes)->toHaveCount(1);
})->group('RF-PD-15');
