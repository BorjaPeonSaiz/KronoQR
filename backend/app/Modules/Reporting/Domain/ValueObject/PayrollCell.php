<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\PayrollColumn;
use App\Modules\Shared\Domain\ValueObject\PayrollDateFormat;
use App\Modules\Shared\Domain\ValueObject\PayrollHoursFormat;
use App\Modules\Shared\Domain\ValueObject\PayrollLayout;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * **Que dice cada celda del fichero de nomina** (**RF-IN-07**, RF-IN-04,
 * RF-PD-01).
 *
 * ## Una sola clase para las dos salidas
 *
 * El CSV y el XLSX de nomina recorren esta misma lista. Es la misma decision que
 * ya tomo `Http\Support\PeriodReportLayout` para los tres formatos del informe
 * por periodo —nombrado en prosa porque una referencia desde `Domain/` hacia
 * `Http/` es una arista que Deptrac prohibe (regla dura 1)— y por el mismo
 * motivo: con las columnas resueltas en cada escritor, una plantilla nueva sale
 * bien en uno y mal en el otro, y quien compare los dos ficheros creera que uno
 * miente.
 *
 * ## Dominio puro, y por eso se prueba sin base de datos ni framework
 *
 * Entra una fila del informe, la plantilla ya resuelta y la zona del centro, y
 * sale texto. Ni traducciones —el rotulo de la cabecera lo resuelve quien sabe en
 * que idioma habla—, ni configuracion —la recibe resuelta, regla dura 14—, ni
 * reloj.
 *
 * ## Las horas: `HH:MM` de serie, decimal si el programa lo exige
 *
 * El decimal es la excepcion razonada al paso 6 de `/informe-nuevo`, y esta
 * explicada en {@see PayrollHoursFormat}. Lo que aqui importa es **como** se
 * calcula, porque es donde se pierde el dinero de alguien:
 *
 *   - **Se redondea al final y una sola vez.** Las horas decimales salen de
 *     dividir los minutos enteros entre 60 y redondear a dos decimales. Redondear
 *     antes —por dia, por tramo— acumularia el error a lo largo del mes.
 *   - **El separador decimal se elige, no se hereda.** Nunca `number_format()`
 *     con la configuracion regional del proceso: `7.75` leido con separador de
 *     miles español es setecientos setenta y cinco.
 *   - **El signo se conserva.** La desviacion por debajo de lo contratado es
 *     negativa, y `-12:30` o `-12.50` son la unica forma honesta de escribirla.
 *   - **`HH:MM` pasa de 24 h.** Esto no es una hora del reloj: el total mensual de
 *     una persona son `168:00`.
 *
 * Las duraciones en reloj las escribe {@see ReportedDuration}, que es donde ya
 * vivia ese formato: no hay un segundo `sprintf` en el producto.
 *
 * ## Las fechas son civiles, no instantes
 *
 * Los `period_start` y `period_end` de una fila son etiquetas de calendario ya
 * expresadas en la zona del centro (RN-05, regla dura 3), asi que aqui no hay
 * ninguna conversion de zona que pueda estar mal puesta. La zona **se escribe**
 * en su propia columna cuando la plantilla la pide, para que quien importe el
 * fichero no tenga que suponerla.
 */
final readonly class PayrollCell
{
    /** No se instancia: es la forma de una celda, no un colaborador. */
    private function __construct() {}

    /**
     * La fila entera, en el orden de la plantilla.
     *
     * @return list<string>
     */
    public static function row(PeriodReportRow $row, PayrollLayout $layout, string $timeZone): array
    {
        return array_map(
            static fn (PayrollColumn $column): string => self::of($column, $row, $layout, $timeZone),
            $layout->columns,
        );
    }

    /**
     * Una celda.
     *
     * ## Por que esta partido en cuatro y no es un solo `match`
     *
     * Un `match` con los veinte casos del catalogo pasa del limite de complejidad
     * ciclomatica del doc 02 §3.5, y el limite tiene razon: los veinte brazos no
     * eran veinte decisiones distintas sino **cuatro familias** —quien es,
     * cuantos dias, cuanto tiempo y que fecha—, y cada una se formatea con una
     * regla propia. Partirlo por familias las deja a la vista y hace que las dos
     * que dependen de la plantilla —duracion y fecha— se elijan por una propiedad
     * del catalogo ({@see PayrollColumn::isDuration()}) y no por enumeracion, que
     * es lo que impide que una columna nueva de duracion salga en `HH:MM` en un
     * formato y en decimal en el otro.
     *
     * **Una columna nueva sigue rompiendo en voz alta**: cae al `default` de
     * {@see self::counter()}, que lanza. Y `PayrollCellTest` recorre
     * `PayrollColumn::cases()` entero, asi que el fallo aparece en la suite y no
     * en la nomina de quinientas personas.
     */
    public static function of(
        PayrollColumn $column,
        PeriodReportRow $row,
        PayrollLayout $layout,
        string $timeZone,
    ): string {
        if ($column->isDuration()) {
            return self::duration(self::minutes($column, $row), $layout->hoursFormat);
        }

        if ($column->isDate()) {
            return self::formatDate(
                $column === PayrollColumn::PeriodFrom ? $row->periodStart : $row->periodEnd,
                $layout->dateFormat,
            );
        }

        return self::identity($column, $row->subject, $timeZone) ?? self::counter($column, $row);
    }

    /**
     * Quien es el sujeto de la fila, y donde. `null` cuando la columna no es de
     * esta familia.
     *
     * **Un literal y no un `match`**, por lo mismo que el catalogo de
     * `SettingKey`: con un brazo por columna, cada `??` que convierte el hueco de
     * un agregado en celda vacia suma un punto de decision y el metodo choca con
     * el limite del §3.5 sin haber ganado ni una rama de negocio. Un literal no
     * tiene puntos de decision, y el `?? ''` queda escrito una sola vez.
     */
    private static function identity(PayrollColumn $column, ReportSubject $subject, string $timeZone): ?string
    {
        $values = [
            PayrollColumn::EmployeeCode->value => $subject->employeeCode,
            PayrollColumn::EmployeeUuid->value => $subject->employeeUuid,
            PayrollColumn::LastName->value => $subject->lastName,
            PayrollColumn::FirstName->value => $subject->firstName,
            // El nombre completo cuando hay persona; si no, la etiqueta del
            // agregado —departamento o centro—, que es de quien habla la fila.
            PayrollColumn::FullName->value => $subject->fullName ?? $subject->label,
            PayrollColumn::Department->value => $subject->label,
            PayrollColumn::TimeZone->value => $timeZone,
        ];

        // `array_key_exists` y no `??` sobre el mapa: la columna puede estar y
        // valer `null` —un agregado no tiene codigo de empleado—, y eso es celda
        // vacia, no «esta columna es de otra familia».
        return \array_key_exists($column->value, $values) ? ($values[$column->value] ?? '') : null;
    }

    /**
     * Los contadores de dias y de tramos. Son numeros enteros y no dependen de
     * la plantilla: no hay nada que formatear.
     */
    private static function counter(PayrollColumn $column, PeriodReportRow $row): string
    {
        return (string) match ($column) {
            PayrollColumn::DaysInPeriod => $row->daysInPeriod,
            PayrollColumn::DaysWithActivity => $row->daysWithActivity,
            PayrollColumn::ShiftCount => $row->shiftCount,
            PayrollColumn::AbsenceDays => $row->absenceDays,
            PayrollColumn::HolidayDays => $row->holidayDays,
            PayrollColumn::UnjustifiedAbsenceDays => $row->unjustifiedAbsenceDays,
            PayrollColumn::DaysWithoutContract => $row->daysWithoutContract,
            default => throw new InvalidArgumentException(
                'La columna de nomina "'.$column->value.'" no tiene celda definida.',
            ),
        };
    }

    /**
     * Los minutos que mide cada columna de duracion.
     *
     * `default` inalcanzable —solo se llama con `isDuration()`— y aun asi lanza:
     * si alguien añadiera una duracion al catalogo sin pasarla por aqui, el fallo
     * seria ruidoso en lugar de una columna de ceros en la nomina.
     */
    private static function minutes(PayrollColumn $column, PeriodReportRow $row): int
    {
        return match ($column) {
            PayrollColumn::WorkedHours => $row->workedMinutes,
            PayrollColumn::ContractedHours => $row->contractedMinutes,
            PayrollColumn::DeviationHours => $row->deviationMinutes(),
            PayrollColumn::OvertimeHours => $row->overtimeMinutes(),
            default => throw new InvalidArgumentException(
                'La columna de nomina "'.$column->value.'" no mide una duracion.',
            ),
        };
    }

    /**
     * Una duracion en minutos, escrita como la plantilla pida.
     *
     * Publico porque lo ejercita la unitaria y porque la cabecera de criterios de
     * la descarga necesita escribir el total con el mismo formato que las celdas:
     * un total en `HH:MM` sobre columnas decimales seria el tipo de incoherencia
     * que hace dudar del fichero entero.
     */
    public static function duration(int $minutes, PayrollHoursFormat $format): string
    {
        if (! $format->isDecimal()) {
            return ReportedDuration::ofMinutes($minutes)->toClockText();
        }

        // Una division y un redondeo, al final y una sola vez. `abs()` antes de
        // formatear y el signo delante: sin eso, -30 minutos saldria «-0.50» por
        // el camino corto y «-0.-50» por el largo, segun como se partiera.
        $decimal = number_format(abs($minutes) / 60, 2, $format->decimalSeparator(), '');

        return ($minutes < 0 ? '-' : '').$decimal;
    }

    /**
     * Una fecha civil, escrita como la plantilla pida.
     *
     * `DateTimeImmutable::format()` sobre una etiqueta de calendario: no se
     * convierte de zona porque no hay nada que convertir (ver el docblock de la
     * clase).
     */
    public static function formatDate(DateTimeImmutable $day, PayrollDateFormat $format): string
    {
        return $day->format(match ($format) {
            PayrollDateFormat::Iso => 'Y-m-d',
            PayrollDateFormat::DayMonthYear => 'd/m/Y',
        });
    }
}
