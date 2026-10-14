<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Collector;

use Throwable;

/**
 * Una parte de una seccion del paquete que puede caer **sin llevarse la
 * seccion entera** (V4-PL-1, ADR-020).
 *
 * El caso de uso ya aisla cada seccion; esto aisla cada parte dentro de una.
 * Con PostgreSQL caido, `installation` y `configuration` perdian la version del
 * producto y el `.env` filtrado —que no dependen de la base— porque lo primero
 * que hacian era leer los ajustes guardados.
 *
 * Lo que queda en lugar de la parte es `{"status": "unavailable", "reason":
 * <clase>}`: la **clase** de la excepcion y nunca su mensaje, que en un
 * `PDOException` lleva la cadena de conexion. El paquete viaja al fabricante.
 */
final class SectionPart
{
    private function __construct() {}

    /**
     * @template TPart
     *
     * @param  callable(): TPart  $part
     * @return TPart|array{status: 'unavailable', reason: class-string<Throwable>}
     */
    public static function of(callable $part): mixed
    {
        try {
            return $part();
        } catch (Throwable $failure) {
            return ['status' => 'unavailable', 'reason' => $failure::class];
        }
    }
}
