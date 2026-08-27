<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Exception;

use RuntimeException;

/**
 * No hay ningun tramo **vigente** con ese identificador.
 *
 * Es de `Application` y no de `Domain` porque no expresa ninguna regla del
 * negocio: expresa que la peticion apunta a algo que no esta. Quien llama lo
 * traduce a `404` —o a `409` si el historico revela que el tramo existio y ya
 * fue anulado o sustituido, que es lo que pasa cuando dos responsables corrigen
 * a la vez (ADR-026).
 */
final class ShiftEntryNotFound extends RuntimeException
{
    public function __construct(public readonly string $shiftEntryUuid)
    {
        parent::__construct('There is no current shift entry with uuid '.$shiftEntryUuid.'.');
    }

    public static function withUuid(string $shiftEntryUuid): self
    {
        return new self($shiftEntryUuid);
    }
}
