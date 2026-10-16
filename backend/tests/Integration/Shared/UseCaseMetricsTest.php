<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\UseCaseMetrics;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\RedisMetricReader;
use App\Modules\Shared\Infrastructure\Metrics\RedisUseCaseMetrics;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Support\Facades\DB;
use Prometheus\RenderTextFormat;

/*
 * `use_case_duration_seconds{use_case,outcome}` sobre Redis de verdad (doc 02
 * §8.2, bloque 24 de la 2.2.1).
 *
 * Tres cosas que la unitaria no puede ver: el formato del HASH que deja el
 * adaptador —el mismo de `queue_job_duration_seconds`, con TODOS los cubos y el
 * `+Inf`—, que el proveedor de `Shared` enlaza el registro y el ayudante llega a
 * Redis sin que el caso de uso reciba nada por constructor, y que `/metrics` lo
 * publica con las dos etiquetas y nada mas.
 */

/** @return list<string> */
function useCaseMetricsKeys(): array
{
    /** @var list<string>|false $keys */
    $keys = resolve(Redis::class)->connection()->command('KEYS', [RedisUseCaseMetrics::DURATION.':*']);

    // `KEYS` devuelve las claves CON el prefijo del cliente (`REDIS_PREFIX`) y
    // `DEL` lo vuelve a poner: se quita aqui para que las dos hablen igual.
    $prefix = config()->string('database.redis.options.prefix', '');

    return array_map(
        static fn (string $key): string => $prefix !== '' && str_starts_with($key, $prefix) ? substr($key, \strlen($prefix)) : $key,
        \is_array($keys) ? $keys : [],
    );
}

function useCaseMetricsClean(): void
{
    $keys = useCaseMetricsKeys();

    if ($keys !== []) {
        resolve(Redis::class)->connection()->command('DEL', $keys);
    }
}

beforeEach(function (): void {
    useCaseMetricsClean();
});

afterEach(function (): void {
    useCaseMetricsClean();
});

it('deja un histograma con sum, count, todos los cubos y +Inf', function (): void {
    new RedisUseCaseMetrics(resolve(Redis::class))->observe('workforce.register_employee', 'ok', 0.07);

    /** @var array<string, string> $campos */
    $campos = resolve(Redis::class)->connection()->command('HGETALL', [
        RedisUseCaseMetrics::DURATION.':use_case=workforce.register_employee,outcome=ok',
    ]);

    // Todos los cubos, tambien los que no contienen la observacion: el numero
    // de escrituras no depende de la duracion (RS-03).
    $esperados = ['sum', 'count', 'le=+Inf'];

    foreach (RedisUseCaseMetrics::BUCKETS as $limite) {
        $esperados[] = 'le='.$limite;
    }

    expect(array_keys($campos))->toEqualCanonicalizing($esperados)
        ->and($campos['count'])->toBe('1')
        ->and($campos['le=+Inf'])->toBe('1')
        ->and($campos['le=0.05'])->toBe('0')
        ->and($campos['le=0.1'])->toBe('1')
        ->and((float) $campos['sum'])->toEqualWithDelta(0.07, 0.0001);
})->group('RQ-06', 'RNF-D-01');

it('el ayudante llega a Redis por el registro que enlaza el proveedor', function (): void {
    UseCaseInstrumentation::run('workforce.create_site', static fn (): bool => true);

    try {
        UseCaseInstrumentation::run('workforce.create_site', static function (): never {
            throw new DomainException('el centro ya existe');
        });
    } catch (DomainException) {
        // Esperado: lo que se mira es la serie.
    }

    // `toContain` y no la lista exacta: otra prueba que corra a la vez puede
    // estar midiendo sus propios casos de uso en el mismo Redis.
    expect(useCaseMetricsKeys())
        ->toContain(RedisUseCaseMetrics::DURATION.':use_case=workforce.create_site,outcome=rejected')
        ->toContain(RedisUseCaseMetrics::DURATION.':use_case=workforce.create_site,outcome=ok');
})->group('RQ-06', 'RNF-D-01');

it('/metrics lo publica con use_case y outcome y nada mas', function (): void {
    new RedisUseCaseMetrics(resolve(Redis::class))->observe('identity.log_out', 'ok', 0.002);

    $familias = [];

    foreach (resolve(RedisMetricReader::class)->read() as $familia) {
        $familias[$familia->getName()] = $familia;
    }

    expect($familias)->toHaveKey('use_case_duration_seconds');

    $texto = (new RenderTextFormat)->render([$familias['use_case_duration_seconds']]);

    expect($texto)
        ->toContain('# TYPE use_case_duration_seconds histogram')
        ->toContain('use_case_duration_seconds_count{use_case="identity.log_out",outcome="ok"} 1')
        ->toContain('use_case_duration_seconds_bucket{use_case="identity.log_out",outcome="ok",le="0.01"} 1');
})->group('RQ-06', 'RNF-D-01');

it('con una transaccion abierta no toca Redis hasta el commit', function (): void {
    $metricas = resolve(UseCaseMetrics::class);
    $clave = RedisUseCaseMetrics::DURATION.':use_case=workforce.register_employee,outcome=ok';
    $conexion = DB::connection();

    $conexion->beginTransaction();

    try {
        $metricas->observe('workforce.register_employee', 'ok', 0.02);

        // Dentro de la transaccion —la que en una importacion tiene tomado el
        // candado de `audit_log`—, Redis no se ha tocado todavia.
        expect(useCaseMetricsKeys())->not->toContain($clave);

        $conexion->commit();
    } finally {
        while ($conexion->transactionLevel() > 0) {
            $conexion->rollBack();
        }
    }

    // Tras el commit, una sola observacion.
    expect(useCaseMetricsKeys())->toContain($clave)
        ->and(resolve(Redis::class)->connection()->command('HGET', [$clave, 'count']))->toBe('1');
})->group('RQ-06', 'RNF-D-01');

it('tras un rollback escribe la observacion igualmente, una sola vez', function (): void {
    $metricas = resolve(UseCaseMetrics::class);
    $clave = RedisUseCaseMetrics::DURATION.':use_case=workforce.register_employee,outcome=error';
    $conexion = DB::connection();

    $conexion->beginTransaction();
    $metricas->observe('workforce.register_employee', 'error', 0.02);

    expect(useCaseMetricsKeys())->not->toContain($clave);

    $conexion->rollBack();

    // Justo la observacion que no se puede perder: la del caso de uso que
    // arrastro la transaccion.
    expect(resolve(Redis::class)->connection()->command('HGET', [$clave, 'count']))->toBe('1');
})->group('RQ-06', 'RNF-D-01');

it('con un punto de guardado anidado escribe una sola vez al confirmar la exterior', function (): void {
    $metricas = resolve(UseCaseMetrics::class);
    $clave = RedisUseCaseMetrics::DURATION.':use_case=workforce.register_employee,outcome=ok';
    $conexion = DB::connection();

    $conexion->transaction(function () use ($metricas, $conexion): void {
        $conexion->transaction(function () use ($metricas): void {
            $metricas->observe('workforce.register_employee', 'ok', 0.02);
        });
    });

    expect(resolve(Redis::class)->connection()->command('HGET', [$clave, 'count']))->toBe('1');
})->group('RQ-06', 'RNF-D-01');
