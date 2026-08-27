<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

/**
 * Estado de un tramo (`shift_entries.status`, doc 01 §5.5).
 *
 * Los cinco valores existen desde el primer dia, aunque la Fase 1 solo produzca
 * los tres primeros: la tabla se crea vacia ahora, y anadir un estado a la tabla
 * con valor probatorio del producto una vez desplegada cuesta una migracion
 * sobre datos de fichaje reales (ADR-026).
 *
 * `voided` y `superseded` son dos hechos legalmente distintos y por eso son dos
 * valores: el primero dice «este tramo no ocurrio» y el segundo «ocurrio, se
 * conserva, y otra version lo sustituye». Colapsarlos haria indistinguibles
 * ante Inspeccion una anulacion y una correccion.
 */
enum ShiftEntryStatus: string
{
    /** Entrada fichada, salida pendiente. */
    case OPEN = 'open';

    /** Cerrado con una duracion dentro de lo esperado. */
    case CLOSED = 'closed';

    /** Cerrado, pero con una duracion que pide revision humana (RN-07, RN-08). */
    case ANOMALOUS = 'anomalous';

    /** Anulado: el tramo no ocurrio. Lo escribe la anulacion de la tarea 1.15. */
    case VOIDED = 'voided';

    /** Sustituido por una version posterior. Lo escribe la correccion de la tarea 1.15 (RN-13, ADR-026). */
    case SUPERSEDED = 'superseded';

    /**
     * Si el tramo cuenta para las invariantes y para el recalculo del total.
     *
     * Es el mismo predicado que `status NOT IN ('voided','superseded')` del
     * indice unico, de la restriccion de exclusion y del agregador de
     * `daily_totals`. Escrito una vez, para que no se repita el literal en cada
     * consulta y acabe divergiendo uno de ellos (ADR-026).
     */
    public function isCurrent(): bool
    {
        return $this !== self::VOIDED && $this !== self::SUPERSEDED;
    }

    public function isOpen(): bool
    {
        return $this === self::OPEN;
    }
}
