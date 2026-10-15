<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

/**
 * Si la zona horaria del centro todavia se puede cambiar desde la API (RN-23,
 * ADR-056).
 *
 * **Es un hecho ya resuelto que el dominio recibe, no una consulta que hace.**
 * Si existe registro horario lo sabe la base de datos —`shift_entries`, en
 * cualquier estado—, y lo pregunta el caso de uso dentro de su transaccion y con
 * la fila del centro bloqueada, por el puerto `WorkRecordPresence`. Es el mismo
 * reparto que con los umbrales legales (regla dura 14): el dominio decide con el
 * dato, no lo busca.
 *
 * Un enumerado y no un `bool` porque `relocateTo($zona, true)` no dice en el
 * sitio de la llamada que significa ese `true`, y porque el motivo del bloqueo
 * —hay registro horario— forma parte de la regla.
 */
enum SiteTimezoneLock
{
    /** Todavia no hay ningun tramo: la zona se cambia libremente. */
    case Open;

    /** Ya existe al menos un tramo, en cualquier estado: la zona es la del historico. */
    case LockedByWorkRecord;

    public static function fromWorkRecordPresence(bool $workRecordExists): self
    {
        return $workRecordExists ? self::LockedByWorkRecord : self::Open;
    }

    public function isLocked(): bool
    {
        return $this === self::LockedByWorkRecord;
    }
}
