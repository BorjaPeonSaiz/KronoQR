<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Como se escribe una fecha en el fichero de nomina
 * (`PAYROLL_EXPORT_DATE_FORMAT`, RF-IN-07).
 *
 * ## Dos formas, y ninguna ambigua
 *
 * `iso` es `AAAA-MM-DD` (ISO 8601), la del resto de la API y la unica que ordena
 * igual como texto que como calendario. `dmy` es `DD/MM/AAAA`, que es lo que
 * exigen muchos importadores de nomina españoles.
 *
 * **No hay `mdy`**, y la ausencia es deliberada: `03/04/2026` significa cosas
 * distintas en los dos lados del Atlantico y un fichero de horas que se pueda
 * leer de dos maneras es un fichero que acabara leido de la equivocada. El dia
 * que un cliente lo necesite de verdad se añade un caso, no se deja un campo
 * libre con un `strftime` dentro.
 *
 * Son **fechas civiles del centro**, no instantes: los `work_date` del informe ya
 * llegan expresados en la zona del centro (regla dura 3, RN-05), asi que aqui no
 * hay ninguna conversion de zona que pueda estar mal puesta.
 */
enum PayrollDateFormat: string
{
    /** `2026-03-01`. El valor de serie. */
    case Iso = 'iso';

    /** `01/03/2026`. */
    case DayMonthYear = 'dmy';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $format): string => $format->value, self::cases());
    }
}
