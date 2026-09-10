<?php

declare(strict_types=1);

use App\Support\Observability\Metrics\DatabaseQueryMetrics;
use App\Support\Observability\Metrics\RedisMetricWriter;

/*
 * Los dos acumuladores transversales de la tarea 3.1:
 * `db_query_duration_seconds{operation}` y el reparto por cubos que comparten
 * con `queue_job_duration_seconds{job}` (doc 02 §8.2, decision 4 de la ficha).
 *
 * **Que se prueba aqui.** La aritmetica: como se clasifica una sentencia y como
 * se reparte una duracion entre los cubos de un histograma. Que lo acumulado
 * acaba en Redis con el nombre y los campos correctos es otra afirmacion, y
 * esta en `tests/Integration/Shared/MetricsExpositionTest.php`, contra Redis de
 * verdad — un doble daria por bueno cualquier nombre de serie.
 */

it('clasifica cada sentencia por su primera palabra', function (string $sql, string $expected): void {
    expect(DatabaseQueryMetrics::operationOf($sql))->toBe($expected);
})->with([
    'select' => ['select * from shift_entries where id = ?', 'select'],
    'insert' => ['insert into scan_events (scan_id) values (?)', 'insert'],
    'update' => ['update daily_totals set worked_minutes = ?', 'update'],
    'delete' => ['delete from error_events where last_seen_at < ?', 'delete'],
    'en mayusculas' => ['SELECT 1', 'select'],
    'con sangria' => ["\n    select 1", 'select'],
    // Todo lo demas cae en un solo cubo: `begin`, `commit`, `set`, `show`, los
    // `savepoint` de las transacciones anidadas. Cinco series y no crecen nunca,
    // que es el techo de cardinalidad que hace util a esta metrica.
    'transaccion' => ['begin', 'other'],
    'confirmacion' => ['commit', 'other'],
    'vacia' => ['', 'other'],
])->group('RQ-06');

it('nunca deja escapar la sentencia a la etiqueta', function (): void {
    // Regla dura 21 y RGPD: `/metrics` viaja al fabricante dentro del paquete de
    // diagnostico (ADR-020), y un valor ligado puede ser un nombre, un DNI o la
    // hora a la que alguien ficho. La etiqueta es SIEMPRE una de cinco palabras.
    $operation = DatabaseQueryMetrics::operationOf(
        "select * from employees where last_name = 'Garcia Perez' and dni = '12345678Z'",
    );

    expect($operation)->toBe('select');
})->group('RL-08', 'RQ-06');

it('reparte una observacion en su cubo y en todos los mayores', function (): void {
    // Es la definicion de un histograma de Prometheus: `_bucket{le="0.1"}`
    // significa «cuantas tardaron 0,1 s o menos», no «cuantas cayeron en ese
    // tramo». Sin la acumulacion, `histogram_quantile()` devuelve numeros que no
    // significan nada.
    $bounds = [0.001, 0.005, 0.02, 0.1];

    expect(RedisMetricWriter::tallyBuckets($bounds, 0.004))->toBe([0, 1, 1, 1]);
})->group('RQ-06');

it('deja fuera de todo cubo lo que supera el ultimo limite', function (): void {
    // Solo cuenta en `+Inf`, que lo escribe el volcado desde `count`. Un cubo de
    // mas seria una observacion inventada.
    $bounds = [0.001, 0.005, 0.02];

    expect(RedisMetricWriter::tallyBuckets($bounds, 5.0))->toBe([0, 0, 0]);
})->group('RQ-06');

it('suma sobre el recuento anterior sin perder cubos', function (): void {
    // La razon de ser del acumulador: una peticion hace varias consultas y se
    // vuelca UNA vez al terminar. Si la suma no fuera acumulativa, cada consulta
    // pisaria a la anterior y el volcado publicaria solo la ultima.
    $bounds = [0.001, 0.005, 0.02];

    $tally = RedisMetricWriter::tallyBuckets($bounds, 0.0005);
    $tally = RedisMetricWriter::tallyBuckets($bounds, 0.01, $tally);
    $tally = RedisMetricWriter::tallyBuckets($bounds, 0.01, $tally);

    expect($tally)->toBe([1, 1, 3]);
})->group('RQ-06');

it('devuelve los cubos por posicion y no por su limite como clave', function (): void {
    // El fallo que esto cierra: `(string) 1.0` es `'1'`, y PHP convierte esa
    // clave en el entero 1. Con los cubos indexados por su limite, un cubo
    // redondo —`1.0`, `5.0`, `60.0`, que son la mitad de los de la cola— acaba
    // en una clave de otro tipo y el volcado no lo encuentra.
    $tally = RedisMetricWriter::tallyBuckets([0.05, 1.0, 60.0], 0.5);

    expect(array_keys($tally))->toBe([0, 1, 2])
        ->and($tally)->toBe([0, 1, 1]);
})->group('RQ-06');
