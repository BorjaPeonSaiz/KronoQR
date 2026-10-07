<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\ProbeFailureClassifier;
use App\Modules\Product\Infrastructure\Diagnostics\ConnectionProbeFailureClassifier;
use App\Support\Database\DatabaseCircuitBreaker;
use App\Support\Database\DatabaseUnavailable;
use App\Support\Resilience\CircuitState;
use Illuminate\Database\QueryException;
use Symfony\Component\Clock\MockClock;
use Tests\Support\Observability\RecordingLogger;

/*
 * Que abre el circuito de PostgreSQL y que no (R3-CH-02).
 *
 * Los mensajes son los que da `pdo_pgsql` de verdad, copiados de la imagen de
 * la aplicacion con el host desaparecido, el puerto cerrado, una direccion que
 * no enruta y una contrasena mala. Solo los tres primeros significan «no hay
 * servidor»: una contrasena mala o un servidor lleno no pueden convertirse en
 * diez segundos de `503` para toda la instalacion.
 */

dataset('database unavailable test inalcanzable', [
    'el nombre no resuelve' => ['SQLSTATE[08006] [7] could not translate host name "postgres" to address: Name does not resolve'],
    'puerto cerrado' => ["SQLSTATE[08006] [7] connection to server at \"127.0.0.1\", port 5432 failed: Connection refused\n\tIs the server running on that host and accepting TCP/IP connections?"],
    'no enruta' => ['SQLSTATE[08006] [7] connection to server at "10.255.255.1", port 5432 failed: timeout expired'],
    'arrancando' => ['SQLSTATE[08006] [7] connection to server at "postgres" (172.28.0.5), port 5432 failed: FATAL:  the database system is starting up'],
]);

dataset('database unavailable test alcanzable', [
    'contrasena mala' => ['SQLSTATE[08006] [7] connection to server at "postgres" (172.28.0.5), port 5432 failed: FATAL:  password authentication failed for user "u"'],
    'servidor lleno' => ['SQLSTATE[08006] [7] connection to server at "postgres" (172.28.0.5), port 5432 failed: FATAL:  sorry, too many clients already'],
]);

it('reconoce un servidor que no esta', function (string $mensaje): void {
    expect(DatabaseUnavailable::isUnreachable(new PDOException($mensaje)))->toBeTrue();
})->with('database unavailable test inalcanzable')->group('RNF-D-03');

it('no confunde un rechazo con un servidor que no esta', function (string $mensaje): void {
    expect(DatabaseUnavailable::isUnreachable(new PDOException($mensaje)))->toBeFalse();
})->with('database unavailable test alcanzable')->group('RNF-D-03');

it('se encuentra en la cadena de excepciones y no lleva host ni usuario (regla dura 21)', function (): void {
    $fallo = DatabaseUnavailable::unreachable(10);
    $envuelta = new RuntimeException('query failed', 0, $fallo);

    expect(DatabaseUnavailable::foundIn($envuelta))->toBe($fallo)
        ->and(DatabaseUnavailable::foundIn(new RuntimeException('otra cosa')))->toBeNull()
        ->and($fallo->retryAfterSeconds)->toBe(10)
        ->and($fallo->getPrevious())->toBeNull()
        ->and($fallo->getMessage())->not->toContain('postgres');
})->group('RNF-D-03');

it('el circuito de la base avisa con su propio nombre y da Retry-After redondeado hacia arriba', function (): void {
    $logger = new RecordingLogger;
    $fichero = sys_get_temp_dir().'/kronoqr-database-circuit-unit-'.getmypid();
    $breaker = new DatabaseCircuitBreaker($fichero, 7.5, new MockClock, $logger);

    try {
        $breaker->recordFailure(new PDOException('Connection refused'));

        expect($breaker->state())->toBe(CircuitState::Open)
            ->and($breaker->retryAfterSeconds())->toBe(8)
            ->and($logger->first()['message'])->toBe('database.circuit_opened')
            ->and($logger->first()['context'])->toBe(['failure' => PDOException::class, 'open_seconds' => 7.5]);
    } finally {
        if (is_file($fichero)) {
            unlink($fichero);
        }
    }
})->group('RNF-D-03');

it('lleva el SQLSTATE de conexion y product:doctor lo clasifica como base de datos, no como fallo del producto (R3-CH-02)', function (): void {
    // Sin SQLSTATE, el `QueryException` que la envuelve llevaba `0` y el doctor
    // decia «fallo del producto, avisa a soporte» con PostgreSQL caido.
    $consulta = new QueryException('pgsql', 'select 1', [], DatabaseUnavailable::circuitOpen(10));

    expect($consulta->getCode())->toBe('08006')
        ->and($consulta->errorInfo[0] ?? null)->toBe('08006')
        ->and((new ConnectionProbeFailureClassifier)->unavailableService($consulta))->toBe(ProbeFailureClassifier::DATABASE);
})->group('RNF-D-03', 'RF-PD-13');
