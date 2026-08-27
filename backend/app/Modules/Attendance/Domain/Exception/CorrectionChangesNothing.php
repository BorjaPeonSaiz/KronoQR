<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

/**
 * Se pidio corregir un tramo dejandole las mismas marcas que ya tenia.
 *
 * **Por que no se acepta en silencio.** Aceptarla escribiria una version nueva
 * identica a la anterior, dejaria la version anterior como `superseded` y
 * anadiria una fila a `shift_corrections` que afirma que algo cambio cuando no
 * cambio nada. En un registro con valor probatorio, cada version tiene que
 * responder a la pregunta «que se rectifico aqui»; una version que no rectifica
 * nada solo sirve para que quien lea el historico busque una diferencia que no
 * existe.
 *
 * Ademas cubre el caso practico: el doble clic en «Guardar» y el reintento de un
 * `PATCH` que ya se aplico. Quien llama lo traduce a `422` sin haber tocado el
 * registro.
 */
final class CorrectionChangesNothing extends AttendanceDomainException
{
    public static function forEntry(string $shiftEntryUuid): self
    {
        return new self('The correction leaves shift entry '.$shiftEntryUuid.' exactly as it was.');
    }
}
