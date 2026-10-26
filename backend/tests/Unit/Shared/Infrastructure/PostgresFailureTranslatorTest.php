<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\DatabaseFailureKind;
use App\Modules\Shared\Infrastructure\Persistence\PostgresFailureTranslator;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;

/*
 * Los fallos de la base de datos, traducidos por el adaptador al vocabulario
 * de los casos de uso (RNF-M-03): el SQLSTATE se lee aqui y en ningun otro
 * sitio.
 */

/** Un fallo de PostgreSQL con ese SQLSTATE en `errorInfo`, como lo deja pdo_pgsql. */
function traductorFalloPostgres(string $sqlState, int|string $code = 7): QueryException
{
    $pdo = new PDOException('SQLSTATE['.$sqlState.']: fallo de prueba');
    $pdo->errorInfo = [$sqlState, 7, 'fallo de prueba'];

    $reflection = new ReflectionProperty(Exception::class, 'code');
    $reflection->setValue($pdo, $code);

    return new QueryException('pgsql', 'SELECT 1 WHERE x = ?', ['valor-enlazado'], $pdo);
}

it('traduce cada SQLSTATE que importa a su clase de fallo', function (string $sqlState, DatabaseFailureKind $clase): void {
    $traducido = (new PostgresFailureTranslator)->translate(traductorFalloPostgres($sqlState));

    expect($traducido?->kind)->toBe($clase);
    expect($traducido?->sqlState)->toBe($sqlState);
})->with([
    'lock_timeout' => ['55P03', DatabaseFailureKind::LockNotAvailable],
    'abrazo' => ['40P01', DatabaseFailureKind::Deadlock],
    'serializacion' => ['40001', DatabaseFailureKind::SerializationFailure],
    'statement_timeout' => ['57014', DatabaseFailureKind::QueryCanceled],
    'indice unico' => ['23505', DatabaseFailureKind::Other],
])->group('RNF-M-03');

it('lee el SQLSTATE de errorInfo aunque el codigo sea un entero', function (): void {
    $traducido = (new PostgresFailureTranslator)->translate(traductorFalloPostgres('57014', 7));

    expect($traducido?->kind)->toBe(DatabaseFailureKind::QueryCanceled);
})->group('RNF-M-03');

it('busca el SQLSTATE en la causa cuando el framework lo envuelve con codigo 0', function (): void {
    $causa = traductorFalloPostgres('55P03');
    $traducido = (new PostgresFailureTranslator)->translate(new DeadlockException('envuelto', 0, $causa));

    expect($traducido?->kind)->toBe(DatabaseFailureKind::LockNotAvailable);
    expect($traducido?->kind->isContention())->toBeTrue();
})->group('RNF-M-03');

it('no traduce lo que no llega como fallo del driver, aunque lleve uno dentro', function (): void {
    $traductor = new PostgresFailureTranslator;

    expect($traductor->translate(new RuntimeException('deadlock detected')))->toBeNull();
    expect($traductor->translate(new RuntimeException('envuelto', 0, traductorFalloPostgres('40P01'))))->toBeNull();
})->group('RNF-M-03');

it('no deja en el mensaje traducido el SQL ni los valores enlazados', function (): void {
    $traducido = (new PostgresFailureTranslator)->translate(traductorFalloPostgres('23505'));

    expect($traducido?->getMessage())->not->toContain('valor-enlazado')
        ->and($traducido?->getMessage())->not->toContain('SELECT');
    expect($traducido?->getPrevious())->toBeInstanceOf(QueryException::class);
})->group('RNF-M-03');
