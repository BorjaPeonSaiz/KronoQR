<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * El **catalogo cerrado** de columnas que puede llevar la salida a nomina
 * (RF-IN-07, ADR-017, regla dura 13).
 *
 * ## Por que es un enumerado y no texto libre
 *
 * RF-IN-07 pide «el formato que necesite la herramienta de nomina del hotel», y
 * la tentacion es dejar que el cliente escriba el nombre de la columna. Seria un
 * error de dos filos: un `id` mal escrito produciria una columna vacia que nadie
 * ve hasta que el gestor cuadra la nomina, y un `id` libre acabaria siendo una
 * ruta de expresion —«suma esto, resta aquello»— dentro de un fichero de
 * configuracion. El catalogo cerrado deja la **eleccion** y el **orden** en manos
 * del cliente, que es lo que cambia entre programas de nomina, y deja el
 * **significado** en el producto, que es lo que no puede cambiar sin cambiar el
 * calculo.
 *
 * Lo que el cliente si elige libremente es el **rotulo** de cada columna
 * (`id=Etiqueta`), porque eso es lo que casa con la plantilla de importacion de
 * su programa y no significa nada para el calculo.
 *
 * ## Vive en `Shared/Domain` y no en `Reporting`
 *
 * Porque lo necesitan los dos lados de la frontera y ninguno puede importar al
 * otro (doc 02 §1.6, ADR-025): `Reporting` lo usa para escribir el fichero y
 * `Product` —que es quien tiene `installation_settings`— para construir la
 * plantilla desde los ajustes. Es el mismo argumento, y el mismo sitio, que
 * {@see HolidayCalendar}.
 *
 * ## `label()` devuelve una CLAVE de traduccion, nunca un texto
 *
 * El dominio no tiene idioma. La cabecera por omision se resuelve en la capa que
 * sabe en que idioma esta hablando —`lang/{es,en}/reports.php`, apartado
 * `payroll.columns`—, igual que los criterios del informe por periodo. Cuando el
 * cliente configura su propio rotulo, ese rotulo gana y no se traduce: lo ha
 * escrito para que encaje con **su** programa de nomina.
 */
enum PayrollColumn: string
{
    /** Codigo interno del empleado en el hotel. Es la columna con la que casi toda nomina cruza. */
    case EmployeeCode = 'employee_code';

    /** Identificador publico y estable de la persona (doc 01 §5.5). Nunca la clave interna. */
    case EmployeeUuid = 'employee_uuid';

    /** Apellidos, tal como estan en la ficha. */
    case LastName = 'last_name';

    /** Nombre, tal como esta en la ficha. */
    case FirstName = 'first_name';

    /** Nombre y apellidos en una sola celda, para los programas que solo admiten un campo. */
    case FullName = 'full_name';

    /** Nombre del departamento, o vacio para quien no tiene ninguno. */
    case Department = 'department';

    /** Primer dia contado de la fila, ya recortado al periodo pedido. */
    case PeriodFrom = 'period_from';

    /** Ultimo dia contado, inclusive. */
    case PeriodTo = 'period_to';

    /** Dias naturales del tramo de la fila. */
    case DaysInPeriod = 'days_in_period';

    /** Dias del tramo con al menos un fichaje. */
    case DaysWithActivity = 'days_with_activity';

    /** Tramos cerrados que se han contado. */
    case ShiftCount = 'shift_count';

    /** Tiempo trabajado, de `daily_totals` (regla dura 7). */
    case WorkedHours = 'worked_hours';

    /** Tiempo contratado prorrateado por dia natural (RF-IN-03). */
    case ContractedHours = 'contracted_hours';

    /** Trabajado menos contratado, **con signo**. */
    case DeviationHours = 'deviation_hours';

    /**
     * Solo la parte positiva de la desviacion.
     *
     * **No es «hora extraordinaria» en sentido laboral** y el producto no lo
     * llama asi en ningun sitio: eso lo decide el convenio. Es tiempo por encima
     * de lo contratado en el periodo, que es lo que la nomina necesita ver para
     * decidirlo ella.
     */
    case OvertimeHours = 'overtime_hours';

    /** Dias-persona de alta cubiertos por una ausencia activa (RF-GP-04). */
    case AbsenceDays = 'absence_days';

    /** Dias-persona de alta, festivos del perfil y sin ausencia que los cubra (RF-GP-04). */
    case HolidayDays = 'holiday_days';

    /**
     * Dias de alta sin actividad, sin ausencia y sin festivo (RF-GP-04).
     *
     * **No es «dias que faltó a trabajar»**: el producto no conoce el cuadrante,
     * asi que los descansos semanales caen aqui dentro. Quien la ponga en su
     * fichero de nomina tiene que saberlo, y por eso el aviso viaja con los
     * criterios de la exportacion.
     */
    case UnjustifiedAbsenceDays = 'unjustified_absence_days';

    /** Dias del tramo en los que la persona estaba de alta y sin contrato vigente. */
    case DaysWithoutContract = 'days_without_contract';

    /**
     * Zona horaria del centro en la que estan expresadas las jornadas
     * (ADR-040, regla dura 3).
     *
     * Existe porque el paso «las horas se presentan en la zona del centro, y el
     * fichero indica cual» de la ficha 3.9 lo exige: un fichero de horas sin
     * zona obliga a quien lo importa a suponerla.
     */
    case TimeZone = 'time_zone';

    /**
     * La columna con ese identificador, o `null` si el catalogo no la conoce.
     *
     * Devuelve `null` en vez de lanzar porque los dos consumidores quieren cosas
     * distintas con lo desconocido: al **guardar** un ajuste es un `422` que dice
     * cual es el identificador malo, y al **leer** la plantilla se descarta la
     * entrada para que un catalogo que encogio entre versiones no deje a nadie
     * sin exportacion (regla dura 19).
     */
    public static function fromId(string $id): ?self
    {
        return self::tryFrom($id);
    }

    /**
     * Los identificadores del catalogo, en el orden en que se declaran.
     *
     * Es lo que enumera el contrato, lo que valida el ajuste y lo que el panel
     * ofrece en la ayuda en linea. Una sola lista: derivarla del enumerado es lo
     * que impide que una columna nueva exista en el fichero y no en el `422`.
     *
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_map(static fn (self $column): string => $column->value, self::cases());
    }

    /**
     * La clave de traduccion del rotulo por omision, **no el rotulo**.
     *
     * Ver el docblock de la clase: el dominio no tiene idioma. Quien escribe el
     * fichero la resuelve contra `lang/*\/reports.php` en el idioma de la
     * instalacion, que es el del programa que abrira el fichero.
     */
    public function label(): string
    {
        return 'payroll.columns.'.$this->value;
    }

    /**
     * Si la columna es una **duracion** y por tanto la formatea el ajuste de
     * horas (`PAYROLL_EXPORT_HOURS_FORMAT`).
     *
     * Se declara aqui y no en quien escribe la celda para que la respuesta sea
     * una sola: con la lista repartida, una columna nueva de duracion saldria en
     * `HH:MM` en el CSV y en decimal en el XLSX, y quien compare los dos ficheros
     * creera que uno de los dos miente.
     */
    public function isDuration(): bool
    {
        return match ($this) {
            self::WorkedHours, self::ContractedHours, self::DeviationHours, self::OvertimeHours => true,
            default => false,
        };
    }

    /** Si la columna es una **fecha** y por tanto la formatea `PAYROLL_EXPORT_DATE_FORMAT`. */
    public function isDate(): bool
    {
        return $this === self::PeriodFrom || $this === self::PeriodTo;
    }
}
