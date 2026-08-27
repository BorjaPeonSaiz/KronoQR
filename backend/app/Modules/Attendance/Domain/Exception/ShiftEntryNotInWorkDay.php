<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

/**
 * Se pidio corregir o anular un tramo que la jornada no tiene.
 *
 * Son dos situaciones distintas con la misma respuesta, y conviene saber que la
 * segunda existe:
 *
 * - **El tramo no es de esta jornada.** El identificador es de otro dia o de
 *   otra persona. Quien llama lo traduce a `404`.
 * - **El tramo ya no esta vigente.** Fue anulado o ya lo sustituyo otra version,
 *   asi que el repositorio no lo carga en el agregado (ADR-026). Es lo que pasa
 *   cuando dos responsables corrigen el mismo tramo a la vez o cuando alguien
 *   reintenta un `PATCH` con el uuid de la version vieja: el segundo llega tarde
 *   y **no puede escribir encima**, que es justo lo que la regla dura 5 protege.
 *   Quien llama lo traduce a `409` y la respuesta le dice cual es la version
 *   vigente.
 *
 * El agregado no distingue entre las dos porque desde dentro no hay diferencia:
 * en los dos casos el tramo no esta. La capa HTTP si puede, consultando el
 * historico, y ahi es donde tiene sentido hacerlo.
 */
final class ShiftEntryNotInWorkDay extends AttendanceDomainException
{
    public static function withUuid(string $shiftEntryUuid, string $isoDate): self
    {
        return new self(
            'Shift entry '.$shiftEntryUuid.' is not a current entry of the work day '.$isoDate
            .': it belongs to another work day, or it has already been voided or superseded (ADR-026).'
        );
    }
}
