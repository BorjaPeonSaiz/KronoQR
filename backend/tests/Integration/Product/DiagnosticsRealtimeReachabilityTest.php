<?php

declare(strict_types=1);

use App\Modules\Product\Infrastructure\Diagnostics\ServiceInspector;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Support\Facades\DB;

/*
 * REVERB EN EL PAQUETE DE DIAGNOSTICO (V4-PL-2, RF-PD-09, ADR-020).
 *
 * `services.realtime` solo decia como estaba configurado: con Reverb parado, el
 * paquete no distinguia «la presencia no se actualiza porque Reverb no
 * responde» de «porque la licencia caduco». Ahora dice si responde, con una
 * conexion acotada, y **sin el host ni el puerto**, que describen la red del
 * cliente: anonimizado como el resto.
 */

function realtimeInspector(?string $host, ?int $port, string $broadcast = 'reverb', bool $enabled = true): ServiceInspector
{
    return new ServiceInspector(
        database: DB::connection(),
        redis: app(Redis::class),
        migrationsPath: database_path('migrations'),
        queueConnection: 'redis',
        queueName: 'default',
        realtimeEnabled: $enabled,
        broadcastConnection: $broadcast,
        realtimeHost: $host,
        realtimePort: $port,
        realtimeTimeoutSeconds: 0.5,
    );
}

/**
 * Un servidor TCP local en un puerto libre.
 *
 * @return array{0: resource, 1: int}
 */
function realtimeListening(): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);

    if ($server === false) {
        throw new RuntimeException('No se pudo abrir un puerto local para la prueba.');
    }

    $address = (string) stream_socket_get_name($server, false);

    return [$server, (int) substr($address, (int) strrpos($address, ':') + 1)];
}

it('dice que Reverb responde cuando hay quien escuche, sin el host ni el puerto', function (): void {
    [$server, $port] = realtimeListening();

    $realtime = realtimeInspector('127.0.0.1', $port)->realtime();
    fclose($server);

    expect($realtime['reachable'])->toBeTrue()
        ->and($realtime['latency_ms'] ?? null)->toBeFloat()
        ->and(json_encode($realtime))->not->toContain('127.0.0.1')
        ->and(json_encode($realtime))->not->toContain((string) $port);
})->group('RF-PD-09');

it('dice que Reverb no responde con el servicio parado, con un motivo cerrado', function (): void {
    // Un puerto recien liberado: nadie escucha.
    [$server, $port] = realtimeListening();
    fclose($server);

    $realtime = realtimeInspector('127.0.0.1', $port)->realtime();

    expect($realtime['reachable'])->toBeFalse()
        ->and($realtime['error'] ?? null)->toBe('unreachable')
        ->and($realtime['configured'])->toBeTrue();
})->group('RF-PD-09');

it('no pregunta a nadie si el tiempo real esta apagado o no es Reverb', function (string $broadcast, bool $enabled): void {
    expect(realtimeInspector('127.0.0.1', 1, $broadcast, $enabled)->realtime()['reachable'])->toBeNull();
})->with([
    'apagado' => ['reverb', false],
    'driver log' => ['log', true],
    'driver null' => ['null', true],
])->group('RF-PD-09');
