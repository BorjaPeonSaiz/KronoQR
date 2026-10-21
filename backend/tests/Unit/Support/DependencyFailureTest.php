<?php

declare(strict_types=1);

use App\Support\Database\DatabaseUnavailable;
use App\Support\Redis\RedisCircuitOpen;
use App\Support\Resilience\DependencyFailure;
use Illuminate\Database\QueryException;

/*
 * QUE CUENTA COMO DEPENDENCIA CAIDA (ADR-059 §1) Y QUE AVERIA YA CONSTA POR SU
 * CORTACIRCUITOS (§2).
 *
 * El mensaje de libpq solo se mira en la `PDOException` del driver y con un
 * SQLSTATE generico: nunca en el SQL con valores de una `QueryException`, que
 * convertiria en `503` un `409` cuyo dato dijera «no connection to the server».
 */

function dependencyFailureDriver(string $sqlState, string $message): PDOException
{
    $driver = new PDOException('SQLSTATE['.$sqlState.']: '.$message);
    $driver->errorInfo = [$sqlState, 7, $message];

    return $driver;
}

it('reconoce PostgreSQL caido o perdido', function (Throwable $fallo): void {
    expect(DependencyFailure::dependencyOf($fallo))->toBe(DependencyFailure::DATABASE)
        ->and(DependencyFailure::isOnRecordThroughItsCircuit($fallo))->toBeTrue();
})->with([
    'circuito abierto' => [fn (): Throwable => DatabaseUnavailable::circuitOpen(5)],
    'circuito abierto en una QueryException' => [fn (): Throwable => new QueryException('pgsql', 'select 1', [], DatabaseUnavailable::circuitOpen(5))],
    'conexion perdida al deshacer' => [fn (): Throwable => dependencyFailureDriver('HY000', 'General error: 7 no connection to the server')],
    'conexion perdida a mitad de sentencia, envuelta' => [fn (): Throwable => new QueryException('pgsql', 'select 1', [], dependencyFailureDriver('HY000', 'General error: 7 server closed the connection unexpectedly'))],
    'SQLSTATE 57P01' => [fn (): Throwable => dependencyFailureDriver('57P01', 'terminating connection due to administrator command')],
])->group('RNF-D-03');

it('no lee el mensaje en el SQL con valores de una QueryException', function (): void {
    // Un nombre de departamento repetido que dice «no connection to the server»:
    // el servidor contesto (23505), y el dato va en los valores enlazados.
    $driver = dependencyFailureDriver('23505', 'Unique violation: 7 ERROR: duplicate key value violates unique constraint');
    $fallo = new QueryException('pgsql', 'insert into departments (name) values (?)', ['no connection to the server'], $driver);

    expect($fallo->getMessage())->toContain('no connection to the server')
        ->and(DependencyFailure::dependencyOf($fallo))->toBeNull();
})->group('RNF-D-03');

it('no lee el mensaje de un driver cuyo servidor contesto', function (): void {
    expect(DependencyFailure::dependencyOf(dependencyFailureDriver('42P01', 'relation "no connection to the server" does not exist')))->toBeNull();
})->group('RNF-D-03');

it('distingue la primera RedisException, que se informa, del circuito abierto, que ya consta', function (): void {
    $primera = new RedisException('Connection refused');

    expect(DependencyFailure::dependencyOf($primera))->toBe(DependencyFailure::REDIS)
        ->and(DependencyFailure::isOnRecordThroughItsCircuit($primera))->toBeFalse()
        ->and(DependencyFailure::isOnRecordThroughItsCircuit(new RuntimeException('envuelta', 0, RedisCircuitOpen::make())))->toBeTrue();
})->group('RNF-D-03');

it('no clasifica un defecto del producto', function (): void {
    expect(DependencyFailure::dependencyOf(new LogicException('defecto')))->toBeNull()
        ->and(DependencyFailure::isOnRecordThroughItsCircuit(new LogicException('defecto')))->toBeFalse();
})->group('RQ-06');
