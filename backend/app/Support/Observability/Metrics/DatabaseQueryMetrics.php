<?php

declare(strict_types=1);

namespace App\Support\Observability\Metrics;

use Illuminate\Database\Events\QueryExecuted;
use Throwable;

/**
 * `db_query_duration_seconds{operation}` (doc 02 §8.2, decision 4 de la ficha
 * 3.1).
 *
 * ## Se ACUMULA en memoria y se vuelca UNA vez
 *
 * Esta es toda la decision de la clase. Una peticion de fichaje hace varias
 * consultas dentro de su transaccion; un `HINCRBY` por consulta **doblaria los
 * viajes a Redis del camino mas caliente del producto** —y los haria dentro de
 * la transaccion, alargandola—. Aqui se suma en un array y se escribe al
 * terminar la peticion o el trabajo, cuando el cliente ya tiene su respuesta.
 *
 * Consecuencia asumida: si el proceso muere a mitad, se pierde lo acumulado de
 * esa peticion. Es exactamente el intercambio correcto — perder unas cuantas
 * observaciones de una metrica tecnica frente a encarecer cada fichaje.
 *
 * ## La etiqueta es la OPERACION, jamas la sentencia
 *
 * `select`, `insert`, `update`, `delete` y `other`. Cinco series y no crecen.
 * Con la sentencia habria una serie por consulta distinta —miles— y, peor, la
 * sentencia lleva sus valores ligados: **un valor ligado puede ser un nombre, un
 * DNI o la hora a la que alguien ficho** (regla dura 21, y `/metrics` viaja al
 * fabricante dentro del paquete de diagnostico, ADR-020). Ni siquiera con los
 * marcadores `?`: el nombre de la tabla ya seria cardinalidad sin respuesta que
 * dar.
 *
 * ## Medir no puede romper una peticion
 *
 * Todo envuelto. El oyente de `QueryExecuted` corre dentro de cada consulta del
 * producto, incluidas las del fichaje: si esta clase lanzara, tumbaria la
 * transaccion que intenta describir.
 */
final class DatabaseQueryMetrics
{
    public const string DURATION = 'db_query_duration_seconds';

    /**
     * Los cinco cubos que interesan en una base de datos local: por debajo de un
     * milisegundo es una lectura de indice, por encima de cien es una consulta
     * que hay que mirar, y el ultimo cubo separa «lenta» de «esto bloquea».
     *
     * @var list<float>
     */
    private const array BUCKETS = [0.001, 0.005, 0.02, 0.1, 0.5, 2.0];

    /**
     * Lo acumulado desde el ultimo volcado, por operacion. Los cubos van por
     * posicion, alineados con {@see self::BUCKETS}.
     *
     * @var array<string, array{sum: float, count: int, buckets: list<int>}>
     */
    private array $pending = [];

    public function __construct(private readonly RedisMetricWriter $writer) {}

    public function record(QueryExecuted $event): void
    {
        try {
            // `time` viene en milisegundos.
            $seconds = $event->time / 1000;
            $operation = self::operationOf($event->sql);

            $tally = $this->pending[$operation] ?? ['sum' => 0.0, 'count' => 0, 'buckets' => []];

            $this->pending[$operation] = [
                'sum' => $tally['sum'] + $seconds,
                'count' => $tally['count'] + 1,
                'buckets' => RedisMetricWriter::tallyBuckets(self::BUCKETS, $seconds, $tally['buckets']),
            ];
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo: ver el docblock.
        }
    }

    /**
     * Vuelca lo acumulado y se vacia.
     *
     * Se llama al terminar la peticion —`Application::terminate()`, despues de
     * que el cliente tenga su respuesta— y al cerrar cada trabajo de la cola.
     * Vaciar es imprescindible: un *worker* de larga vida volveria a escribir lo
     * mismo en cada trabajo.
     */
    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $operation => $tally) {
            $this->writer->observeMany(
                self::DURATION,
                'operation='.$operation,
                self::BUCKETS,
                $tally['buckets'],
                $tally['sum'],
                $tally['count'],
            );
        }
    }

    /**
     * La primera palabra de la sentencia, y nada mas.
     *
     * Se mira solo el principio a proposito: no hace falta entender el SQL para
     * clasificarlo, y cualquier intento de leerlo mas alla acabaria tocando los
     * valores.
     */
    public static function operationOf(string $sql): string
    {
        $first = strtolower(strtok(ltrim($sql), " \t\n\r") ?: '');

        return \in_array($first, ['select', 'insert', 'update', 'delete'], true) ? $first : 'other';
    }
}
