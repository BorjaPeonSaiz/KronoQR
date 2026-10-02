<?php

declare(strict_types=1);

use App\Modules\Workforce\Application\UseCase\EmployeeWriteRetry;
use App\Modules\Workforce\Domain\Exception\ConcurrentEmployeeWrite;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Mockery\MockInterface;
use Tests\Support\Observability\RecordingLogger;

/*
 * El reintento de las escrituras de la plantilla ante un cruce (ADR-046 §1.3;
 * revision del bloque 17, segunda pasada).
 *
 * El cruce llega siempre desde una transaccion anidada, y ahi Laravel lo
 * convierte en `DeadlockException`: un `PDOException` que no es
 * `QueryException` y cuyo codigo es `0`; el SQLSTATE va en la causa. La primera
 * version solo atrapaba `QueryException`, y el segundo cruce salia como `500`.
 * Aqui se lanza exactamente esa excepcion, con su causa, desde el trabajo.
 */

/** La `QueryException` que lanza PostgreSQL con ese SQLSTATE. */
function cruceDeLaPlantilla(string $sqlState): QueryException
{
    $pdo = new class('SQLSTATE['.$sqlState.']: deadlock detected') extends PDOException
    {
        public function __construct(string $message)
        {
            parent::__construct($message);
            $this->code = substr($message, 9, 5);
        }
    };

    return new QueryException('pgsql', 'UPDATE employees SET email = ?', [], $pdo);
}

/** Lo que Laravel lanza cuando el cruce ocurre en una transaccion anidada. */
function cruceAnidadoDeLaPlantilla(string $sqlState = '40P01'): DeadlockException
{
    $causa = cruceDeLaPlantilla($sqlState);

    return new DeadlockException($causa->getMessage(), 0, $causa);
}

/**
 * Una conexion que ejecuta el trabajo y, en cada intento, lanza lo que diga el
 * guion (o nada).
 *
 * @param  list<Throwable|null>  $guion
 * @param  array{calls: int}  $contador
 */
function conexionDelReintento(array $guion, array &$contador, int $nivel = 0): ConnectionInterface
{
    /** @var ConnectionInterface&MockInterface $conexion */
    $conexion = Mockery::mock(ConnectionInterface::class);
    $conexion->allows('transactionLevel')->andReturn($nivel);
    $conexion->allows('transaction')->andReturnUsing(static function (Closure $trabajo) use (&$guion, &$contador): mixed {
        $contador['calls']++;
        $fallo = array_shift($guion);

        if ($fallo instanceof Throwable) {
            throw $fallo;
        }

        return $trabajo();
    });

    return $conexion;
}

it('reintenta una vez el cruce que llega como DeadlockException y deja un aviso sin datos personales', function (): void {
    $contador = ['calls' => 0];
    $log = new RecordingLogger;
    $reintento = new EmployeeWriteRetry(conexionDelReintento([cruceAnidadoDeLaPlantilla(), null], $contador), $log);

    expect($reintento->run('employee.update', static fn (): string => 'confirmada'))->toBe('confirmada')
        ->and($contador['calls'])->toBe(2)
        ->and($log->lines)->toBe([[
            'level' => 'warning',
            'message' => 'workforce.employee_write_concurrency',
            'context' => ['use_case' => 'employee.update', 'attempt' => 1, 'sqlstate' => '40P01', 'retried' => true],
        ]]);
})->group('RF-GP-01', 'RL-04');

it('responde ConcurrentEmployeeWrite, y no un 500, si el reintento tambien se cruza', function (): void {
    $contador = ['calls' => 0];
    $log = new RecordingLogger;
    $reintento = new EmployeeWriteRetry(
        conexionDelReintento([cruceAnidadoDeLaPlantilla(), cruceAnidadoDeLaPlantilla('40001')], $contador),
        $log,
    );

    expect(fn () => $reintento->run('employee.register', static fn (): string => 'nunca'))
        ->toThrow(ConcurrentEmployeeWrite::class);

    expect($contador['calls'])->toBe(2)
        ->and(array_column($log->lines, 'context'))->toBe([
            ['use_case' => 'employee.register', 'attempt' => 1, 'sqlstate' => '40P01', 'retried' => true],
            ['use_case' => 'employee.register', 'attempt' => 2, 'sqlstate' => '40001', 'retried' => false],
        ]);
})->group('RF-GP-01', 'RL-04');

it('reconoce tambien el cruce que llega como QueryException en la transaccion de fuera', function (): void {
    $contador = ['calls' => 0];
    $reintento = new EmployeeWriteRetry(conexionDelReintento([cruceDeLaPlantilla('40P01'), null], $contador), new RecordingLogger);

    expect($reintento->run('employee.import', static fn (): int => 7))->toBe(7)
        ->and($contador['calls'])->toBe(2);
})->group('RF-GP-01');

it('no reintenta ni traduce un fallo que no es de concurrencia', function (): void {
    $contador = ['calls' => 0];
    $otro = cruceDeLaPlantilla('23505');
    $log = new RecordingLogger;
    $reintento = new EmployeeWriteRetry(conexionDelReintento([$otro], $contador), $log);

    expect(fn () => $reintento->run('employee.update', static fn (): null => null))->toThrow(QueryException::class);

    expect($contador['calls'])->toBe(1)
        ->and($log->lines)->toBe([]);
})->group('RF-GP-01');

it('dentro de una transaccion ajena no reintenta: el cruce sube para que lo reintente la de fuera', function (): void {
    $contador = ['calls' => 0];
    $log = new RecordingLogger;
    $reintento = new EmployeeWriteRetry(conexionDelReintento([cruceAnidadoDeLaPlantilla()], $contador, nivel: 1), $log);

    expect(fn () => $reintento->run('employee.update', static fn (): null => null))->toThrow(DeadlockException::class);

    expect($contador['calls'])->toBe(1)
        ->and($log->lines)->toBe([]);
})->group('RF-GP-01');

it('lee el SQLSTATE de la causa y no de la clase ni del mensaje', function (Throwable $fallo, ?string $sqlState): void {
    expect(EmployeeWriteRetry::concurrencySqlState($fallo))->toBe($sqlState);
})->with([
    'DeadlockException con 40P01 en la causa' => [cruceAnidadoDeLaPlantilla('40P01'), '40P01'],
    'serializacion en la transaccion de fuera' => [cruceDeLaPlantilla('40001'), '40001'],
    'indice unico' => [cruceDeLaPlantilla('23505'), null],
    'cualquier otra cosa' => [new RuntimeException('deadlock detected'), null],
])->group('RF-GP-01');
