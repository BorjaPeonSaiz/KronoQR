<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Como se escribe una duracion en el fichero de nomina
 * (`PAYROLL_EXPORT_HOURS_FORMAT`, RF-IN-07, RF-IN-04).
 *
 * ## La excepcion razonada al «nunca decimal»
 *
 * El paso 6 de la skill `/informe-nuevo` lo dice sin matices: *«horas como texto
 * `HH:MM`, no como decimal: nadie interpreta bien 7,75»*. Esa regla protege a
 * **quien lee**, y en los informes del producto se cumple sin excepcion.
 *
 * El fichero de nomina no lo lee nadie: lo importa un programa, y buena parte de
 * los programas de nomina exigen horas decimales porque multiplican por un
 * precio hora. Entregarles `07:45` significa que alguien las convierte a mano en
 * una hoja aparte, que es justo donde se cometen los errores que la regla quiere
 * evitar.
 *
 * Asi que el decimal existe **aqui y solo aqui**, con tres salvaguardas:
 *
 *   1. `hhmm` sigue siendo el valor de serie. Quien no elija, recibe `HH:MM`.
 *   2. La eleccion queda escrita: en la fila de la exportacion, en el asiento de
 *      auditoria del cambio de ajuste y en los criterios que acompañan a la
 *      descarga.
 *   3. El separador decimal **se elige**, no se hereda de la configuracion
 *      regional del proceso. `7.75` leido con separador de miles español es
 *      setecientos setenta y cinco, y esa ambigüedad es justo lo que hunde a
 *      quien confia en `number_format()`.
 */
enum PayrollHoursFormat: string
{
    /**
     * `HH:MM`, con horas por encima de 24 y con signo cuando la desviacion es
     * negativa (`168:00`, `-12:30`). El valor de serie.
     */
    case HoursMinutes = 'hhmm';

    /** `7.75` — decimal con punto, dos decimales. */
    case DecimalDot = 'decimal_dot';

    /** `7,75` — decimal con coma, dos decimales. */
    case DecimalComma = 'decimal_comma';

    /** Si la duracion sale como numero decimal en lugar de como reloj. */
    public function isDecimal(): bool
    {
        return $this !== self::HoursMinutes;
    }

    /** El separador decimal que corresponde. Irrelevante en `hhmm`. */
    public function decimalSeparator(): string
    {
        return $this === self::DecimalComma ? ',' : '.';
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $format): string => $format->value, self::cases());
    }
}
