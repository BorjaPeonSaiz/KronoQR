<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

/**
 * Que se hizo con el tramo (`shift_corrections.action`, doc 01 §5.5).
 *
 * Son los cuatro verbos que enumera RF-PA-04 —«crear, modificar hora, cerrar
 * turno abierto, anular»— y ni uno mas. No es una lista abierta: cada valor
 * describe un hecho distinto ante Inspeccion y tiene su propia entrada en el
 * catalogo de `AuditAction` (`shift_entry.created`, `.modified`, `.closed`,
 * `.voided`), que ya existe desde la tarea 1.14. Los valores respaldados
 * coinciden con el sufijo de esa accion a proposito: quien escribe el asiento
 * traduce sin inventarse el nombre.
 *
 * **La decide el dominio, no quien llama.** Cerrar un turno abierto y cambiarle
 * la hora de salida a uno ya cerrado son la misma peticion desde fuera —dos
 * instantes nuevos para un tramo—, y lo que las distingue es el estado anterior
 * del tramo, que solo el agregado conoce. Si el cliente declarase la accion,
 * podria declararla mal y el trail contaria otra cosa que la que paso.
 */
enum CorrectionAction: string
{
    /** Alta manual de un tramo que nunca se ficho (RF-PA-04, motivo `ALTA_RETROACTIVA` u `OLVIDO_*`). */
    case CREATED = 'created';

    /** Cambio de hora sobre un tramo que ya estaba cerrado. */
    case MODIFIED = 'modified';

    /** Cierre manual de un tramo que estaba abierto: el olvido de fichaje de salida. */
    case CLOSED = 'closed';

    /** Anulacion: este tramo no ocurrio (ADR-026). No crea version nueva. */
    case VOIDED = 'voided';

    /**
     * Que accion describe pasar de un tramo con estas marcas a estas otras.
     *
     * Sale de aqui y no de un `if` en el caso de uso porque es la unica regla
     * que distingue `closed` de `modified`, y esa distincion es la que permite
     * contar cuantos olvidos de fichaje de salida hubo el mes pasado.
     */
    public static function betweenTimes(ShiftTimes $before, ShiftTimes $after): self
    {
        return $before->isOpen() && ! $after->isOpen() ? self::CLOSED : self::MODIFIED;
    }

    /**
     * Si la accion deja una version nueva del tramo (RN-13).
     *
     * Las tres primeras si; anular no: un tramo que no ocurrio no tiene version
     * corregida, tiene un estado terminal (ADR-026).
     */
    public function createsNewVersion(): bool
    {
        return $this === self::MODIFIED || $this === self::CLOSED;
    }
}
