<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Command;

use InvalidArgumentException;

/**
 * Un lote de escaneos **ya ordenado por `occurred_at`** (doc 02 §6, RF-KI-04).
 *
 * ## Por que existe una clase para ordenar una lista
 *
 * Porque el orden es la unica parte del lote que puede corromper el registro
 * horario de alguien, y tiene que poder probarse sin base de datos.
 *
 * La cola offline del quiosco reintenta con retroceso exponencial, asi que sus
 * elementos **no llegan en el orden en que ocurrieron**: basta con que la salida
 * se reintente antes que la entrada. Procesados en orden de llegada, esa pareja
 * produce un cierre sin turno abierto —que `WorkDay` interpreta como una entrada
 * nueva— seguido de una apertura, es decir, una jornada inventada de la que nadie
 * sospecha porque las dos horas son reales. Ordenados por `occurred_at`, el
 * agregado ve la secuencia que de verdad ocurrio.
 *
 * Ordenar en el `usort` del controlador habria funcionado igual de bien y
 * habria sido imposible de probar en la suite unitaria, que es donde el §9.5
 * situa esta comprobacion.
 *
 * ## El desempate no es cosmetico
 *
 * Dos escaneos pueden compartir `occurred_at` al milisegundo —dos personas en dos
 * quioscos, o un reloj con poca resolucion—. Sin desempate, el orden lo decidiria
 * la implementacion de `usort`, y dos envios identicos podrian procesarse en
 * ordenes distintos: la misma peticion daria resultados distintos. Se desempata
 * por `scanId`, que es un UUID v7 y por tanto **ordenable por el momento en que
 * el quiosco lo genero** (regla dura 8, doc 02 §6). Es el criterio mas cercano al
 * orden real que existe sin preguntarle a nadie.
 *
 * ## Lo que NO hace
 *
 * No valida, no deduplica y no decide nada. Un lote con dos veces el mismo
 * `scan_id` se procesa entero: el segundo choca con el UNIQUE de
 * `scan_events.scan_id` y se responde con el resultado del primero (regla dura 8,
 * RF-AT-07). Deduplicar aqui seria un `SELECT` previo con otro nombre.
 */
final readonly class ScanBatch
{
    /**
     * @param  list<RegisterScanCommand>  $scans  Ordenados por `occurred_at` ascendente.
     */
    private function __construct(public array $scans) {}

    /**
     * @param  list<RegisterScanCommand>  $scans  En cualquier orden.
     *
     * @throws InvalidArgumentException si el lote viene vacio: un envio sin
     *                                  elementos es un fallo del cliente, no un
     *                                  lote que no cambia nada.
     */
    public static function of(array $scans): self
    {
        if ($scans === []) {
            throw new InvalidArgumentException('Un lote de sincronizacion necesita al menos un escaneo.');
        }

        usort($scans, static function (RegisterScanCommand $left, RegisterScanCommand $right): int {
            $byInstant = $left->occurredAt <=> $right->occurredAt;

            return $byInstant !== 0 ? $byInstant : strcmp($left->scanId, $right->scanId);
        });

        return new self($scans);
    }

    public function size(): int
    {
        return \count($this->scans);
    }

    /**
     * El escaneo mas antiguo del lote, que es el que mide el retraso de la
     * sincronizacion (`sync_delay_seconds`, §8.2).
     *
     * Sale de la primera posicion porque el lote ya esta ordenado: preguntarlo
     * dos veces daria la misma respuesta y recorrer la lista otra vez seria
     * afirmar que el orden podria no ser el que es.
     */
    public function earliest(): RegisterScanCommand
    {
        return $this->scans[0];
    }
}
