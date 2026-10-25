<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Exception\ScanAlreadyRecorded;
use App\Modules\Attendance\Application\Port\ScanResult;
use App\Modules\Attendance\Domain\Exception\OverlappingShiftEntry;
use App\Modules\Attendance\Domain\Exception\ShiftEntryAlreadyOpen;
use App\Modules\Attendance\Infrastructure\Adapter\ScanFailureTransience;
use App\Support\Database\DatabaseUnavailable;
use App\Support\Redis\RedisCircuitOpen;
use Illuminate\Database\QueryException;
use Illuminate\Support\Env;

/*
 * **Que fallo cuenta para descartar un fichaje** (RN-22, ADR-061 §2), sin base
 * de datos: la clasificacion es una lista de EXCLUSION, asi que lo que importa
 * es que cada cosa transitoria o de negocio quede fuera —tambien envuelta en
 * `previous`— y que lo no previsto cuente.
 */

function fallaConSqlstate(string $sqlState, string $mensaje = 'SQLSTATE error'): QueryException
{
    $driver = new PDOException($mensaje);
    $driver->errorInfo = [$sqlState, 1, $mensaje];

    return new QueryException('pgsql', 'insert into scan_events values (?)', ['x'], $driver);
}

dataset('fallos que no cuentan', [
    'base de datos inalcanzable (ADR-059)' => fn (): Throwable => new RuntimeException('envuelta', previous: DatabaseUnavailable::unreachable(10)),
    'conexion perdida 08006' => fn (): Throwable => fallaConSqlstate('08006'),
    'servidor apagandose 57P01' => fn (): Throwable => fallaConSqlstate('57P01'),
    'Redis caido' => fn (): Throwable => new RedisException('Connection refused'),
    'circuito de Redis abierto' => fn (): Throwable => RedisCircuitOpen::make(),
    'serializacion 40001' => fn (): Throwable => fallaConSqlstate('40001'),
    'interbloqueo 40P01' => fn (): Throwable => fallaConSqlstate('40P01'),
    'candado no disponible 55P03' => fn (): Throwable => fallaConSqlstate('55P03'),
    'disco lleno, clase 53' => fn (): Throwable => fallaConSqlstate('53100'),
    'statement_timeout, clase 57' => fn (): Throwable => fallaConSqlstate('57014'),
    'error de E/S, clase 58' => fn (): Throwable => fallaConSqlstate('58030'),
    'interbloqueo envuelto en previous' => fn (): Throwable => new RuntimeException('envuelta', previous: fallaConSqlstate('40P01')),
    'carrera de idempotencia por el UNIQUE de scan_id' => fn (): Throwable => fallaConSqlstate('23505', 'duplicate key value violates unique constraint "scan_events_scan_id_unique"'),
    'carrera de RN-01' => fn (): Throwable => ShiftEntryAlreadyOpen::forEmployee('0199f0c2-0000-7000-8000-000000000009'),
    'solape de RN-02' => fn (): Throwable => OverlappingShiftEntry::at('0199f0c2-0000-7000-8000-000000000009', new DateTimeImmutable('2026-08-14T07:00:00Z')),
    'reenvio idempotente' => fn (): Throwable => new ScanAlreadyRecorded('0199f0c2-0000-7000-8000-000000000001'),
    'reenvio ilegible envuelto' => fn (): Throwable => new RuntimeException('no legible', previous: new ScanAlreadyRecorded('0199f0c2-0000-7000-8000-000000000001')),
]);

dataset('fallos que cuentan', [
    'un Error de PHP' => fn (): Throwable => new TypeError('tipo'),
    'una LogicException' => fn (): Throwable => new LogicException('invariante roto'),
    'una RuntimeException sin clasificar' => fn (): Throwable => new RuntimeException('defecto'),
    'dato invalido, clase 22' => fn (): Throwable => fallaConSqlstate('22003'),
    'integridad de otra clave, clase 23' => fn (): Throwable => fallaConSqlstate('23505', 'duplicate key value violates unique constraint "otra_clave"'),
    'violacion de CHECK 23514' => fn (): Throwable => fallaConSqlstate('23514'),
]);

it('no cuenta lo transitorio ni lo que ya es un desenlace', function (Closure $fallo): void {
    expect((new ScanFailureTransience)->counts($fallo()))->toBeFalse();
})->with('fallos que no cuentan')->group('RN-22', 'RNF-D-03');

it('cuenta lo que no reconoce como transitorio', function (Closure $fallo): void {
    expect((new ScanFailureTransience)->counts($fallo()))->toBeTrue();
})->with('fallos que cuentan')->group('RN-22');

it('declara para cada desenlace si creo tramo, el descarte incluido', function (ScanResult $resultado): void {
    $accion = $resultado->action();

    expect($accion === null)->toBe(! $resultado->isAccepted());
})->with(ScanResult::cases())->group('RN-22', 'RF-AT-12');

it('distingue el descarte del rechazo en el resultado', function (): void {
    expect(ScanResult::DISCARDED_UNPROCESSABLE->isDiscard())->toBeTrue()
        ->and(ScanResult::DISCARDED_UNPROCESSABLE->isRejection())->toBeTrue()
        ->and(ScanResult::DISCARDED_UNPROCESSABLE->isAccepted())->toBeFalse()
        ->and(ScanResult::REJECTED_UNKNOWN->isDiscard())->toBeFalse();
})->group('RN-22');

it('trae de serie 10 intentos, 60 minutos y un cupo de 3 en config/attendance.php', function (): void {
    foreach (['SCAN_DISCARD_MIN_ATTEMPTS', 'SCAN_DISCARD_MIN_MINUTES', 'SCAN_DISCARD_DAILY_BUDGET'] as $variable) {
        Env::getRepository()->clear($variable);
    }

    /** @var array{scan_discard: array<string, string|int>} $config */
    $config = require dirname(__DIR__, 4).'/config/attendance.php';

    expect($config['scan_discard'])->toBe(['min_attempts' => '10', 'min_minutes' => '60', 'daily_budget' => '3', 'budget_ceiling' => 20]);
})->group('RN-22');
