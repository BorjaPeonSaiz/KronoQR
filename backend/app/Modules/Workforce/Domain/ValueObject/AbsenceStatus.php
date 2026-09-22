<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

/**
 * Situacion de una version de una ausencia (**RF-GP-04**, RN-13, regla dura 5).
 *
 * Las tres filas viven en la tabla para siempre: nada se borra ni se
 * sobrescribe. Lo unico que cambia es **cual entra en el conjunto vigente**, que
 * es lo que cuenta para el informe y lo que la restriccion de exclusion
 * `absences_no_overlap` vigila.
 *
 * **Tres valores y ni uno mas.** No hay `pending` ni `approved`: este producto
 * registra ausencias **sin flujo de aprobacion** (doc 05 §8), y un estado de
 * aprobacion en el catalogo seria una promesa escrita en el esquema que el
 * producto no cumple.
 */
enum AbsenceStatus: string
{
    /** La version vigente. Es la unica que cuenta para el informe. */
    case Active = 'active';

    /**
     * Sustituida por una correccion posterior (RN-13).
     *
     * Conserva intactos su tipo, sus fechas, su nota, su autor y su momento: lo
     * unico que se escribio sobre ella al corregir fueron `status` y
     * `superseded_by_id`, igual que en `shift_entries` (ADR-026, ADR-035).
     */
    case Superseded = 'superseded';

    /**
     * Anulada: se declara que el hecho **no ocurrio**.
     *
     * No crea version posterior, porque no hay una version siguiente de algo que
     * no paso. Lleva autor, momento y motivo.
     */
    case Voided = 'voided';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
