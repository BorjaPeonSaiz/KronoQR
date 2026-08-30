<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Support;

use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportRow;
use App\Modules\Reporting\Domain\ValueObject\ReportedDuration;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Lang;
use RuntimeException;

/**
 * **Que columnas lleva el informe exportado y que dice cada celda**
 * (**RF-IN-04**).
 *
 * ## Escrito una vez para los tres formatos
 *
 * CSV, XLSX y PDF recorren esta misma lista. Es la misma decision que ya tomaron
 * el escritor de la exportacion legal y el del historico propio del portal, y por
 * el mismo motivo: con las columnas declaradas en cada escritor, una columna
 * nueva acaba rotulada en un fichero y vacia —o ausente— en el otro, y quien
 * compare los dos creera que faltan datos.
 *
 * ## Las horas son `HH:MM` y **no hay ninguna columna de minutos**
 *
 * La respuesta JSON si lleva las dos —`worked_minutes` para quien calcula y
 * `worked` para quien lee— porque quien la consume es un programa. Un fichero de
 * hoja de calculo lo abre una persona, y una columna de minutos al lado de una
 * de `HH:MM` es una invitacion a dividir entre 60 y volver al decimal que
 * `/informe-nuevo` prohibe. Quien necesite calcular tiene la API.
 *
 * Las cuatro duraciones salen de {@see ReportedDuration}, incluida la desviacion
 * con signo (`-12:30`) y los totales de mas de 24 h (`168:00`).
 *
 * ## `employee_uuid` va en el fichero, y no contradice la regla dura 21
 *
 * Aquella prohibe **nombres** en logs tecnicos. Esto es lo contrario: un fichero
 * cuya finalidad es llevar datos de personas identificadas, y el identificador
 * publico es lo que permite cruzarlo con el registro legal sin depender de que
 * dos personas no se llamen igual. El `uuid` no aparece en el **nombre** del
 * fichero, que es lo que se ve desde fuera.
 *
 * ## Los rotulos salen de `lang/{es,en}/reports.php`
 *
 * El idioma del documento es configuracion de la instalacion (regla dura 13,
 * ADR-017). Si falta una traduccion se rompe en voz alta, igual que en el
 * `Resource`: una clave suelta en la cabecera de un informe de horas es peor que
 * un error, porque nadie la lee como un fallo.
 */
final readonly class PeriodReportLayout
{
    /**
     * Las columnas, en orden. La clave es la de `reports.columns` de `lang/`.
     *
     * @var list<string>
     */
    public const array COLUMNS = [
        'subject_kind',
        'subject',
        'employee_code',
        'employee_uuid',
        'department_id',
        'period_from',
        'period_to',
        'worked',
        'contracted',
        'deviation',
        'overtime',
        'shift_count',
        'days_in_period',
        'days_with_activity',
        'days_without_activity',
        'open_shift_days',
        'incident_days',
        'days_without_contract',
    ];

    /**
     * Ancho de cada columna del XLSX, en caracteres, en el orden de
     * {@see self::COLUMNS}.
     *
     * **No es cosmetica.** Sin anchos, una hoja de calculo abre las columnas de
     * fecha con `#####` y los nombres largos cortados: quien la recibe tiene que
     * ajustarlas a mano antes de poder leer nada. Los numeros van estrechos y el
     * sujeto ancho, que es donde estan los apellidos compuestos.
     *
     * @var list<float>
     */
    public const array COLUMN_WIDTHS = [
        12.0, 32.0, 14.0, 38.0, 12.0, 12.0, 12.0,
        10.0, 12.0, 12.0, 10.0,
        10.0, 12.0, 14.0, 16.0, 14.0, 12.0, 16.0,
    ];

    private function __construct() {}

    /**
     * La fila de rotulos.
     *
     * @return list<string>
     */
    public static function header(): array
    {
        return array_map(
            static fn (string $column): string => self::text('columns.'.$column),
            self::COLUMNS,
        );
    }

    /**
     * Una fila de datos, en el mismo orden que {@see self::header()}.
     *
     * @return list<string>
     */
    public static function cells(PeriodReportRow $row): array
    {
        return [
            self::text('subject_kind.'.$row->subject->kind->value),
            // El nombre de la persona cuando la hay, y si no la etiqueta del
            // agregado. `null` es el cubo de quien no tiene departamento y se
            // rotula desde `lang/`, no se inventa aqui.
            $row->subject->fullName ?? $row->subject->label ?? self::text('subject.unassigned'),
            $row->subject->employeeCode ?? '',
            $row->subject->employeeUuid ?? '',
            (string) ($row->subject->departmentId ?? ''),
            $row->isoPeriodStart(),
            $row->isoPeriodEnd(),
            ReportedDuration::ofMinutes($row->workedMinutes)->toClockText(),
            ReportedDuration::ofMinutes($row->contractedMinutes)->toClockText(),
            ReportedDuration::ofMinutes($row->deviationMinutes())->toClockText(),
            ReportedDuration::ofMinutes($row->overtimeMinutes())->toClockText(),
            (string) $row->shiftCount,
            (string) $row->daysInPeriod,
            (string) $row->daysWithActivity,
            (string) $row->daysWithoutActivity(),
            (string) $row->openShiftDays,
            (string) $row->incidentDays,
            (string) $row->daysWithoutContract,
        ];
    }

    /**
     * El bloque de cabecera del documento: rotulo y valor, en orden de lectura.
     *
     * **Va visible dentro del fichero, nunca en las propiedades del documento.**
     * `/informe-nuevo` lo exige por escrito y el motivo es practico: nadie abre
     * las propiedades de un XLSX, y el fichero se lee dos años despues sin nadie
     * al lado que lo explique.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function metadata(PeriodReport $report, ?string $issuer, string $digest): array
    {
        return [
            [self::text('document.period'), $report->range->isoFrom().' → '.$report->range->isoTo()],
            [self::text('document.granularity'), self::text('granularity.'.$report->granularity->value)],
            [self::text('document.group_by'), self::text('subject_kind.'.$report->grouping->value)],
            [self::text('document.time_zone'), $report->timeZone],
            [self::text('document.generated_at'), self::localInstant($report->generatedAt, $report->timeZone)],
            // El nombre de la cuenta emisora, nunca su correo. Ver el puerto
            // `ReportIssuerDirectory`.
            [self::text('document.issuer'), $issuer ?? self::text('document.issuer_unknown')],
            [self::text('document.rows'), (string) $report->rowCount()],
            [self::text('document.digest'), $digest],
        ];
    }

    /**
     * Los criterios de inclusion, ya traducidos y en el orden en el que se leen.
     *
     * @return list<string>
     */
    public static function criteria(PeriodReport $report): array
    {
        $lines = array_map(
            static fn (string $key): string => self::text($key),
            $report->criteria,
        );

        if (! $report->contractCoverage->isComplete()) {
            // El aviso de cobertura va CON los criterios y no en una nota al pie:
            // un informe comparado contra un contrato que no existe sale con una
            // desviacion enorme y con aspecto de dato bueno.
            $lines[] = self::text('document.contract_coverage', [
                'days' => (string) $report->contractCoverage->daysWithoutContract,
                'employees' => (string) $report->contractCoverage->employeesWithoutContract,
            ]);
        }

        return $lines;
    }

    /**
     * El titulo del documento y el nombre del fichero, que no es lo mismo.
     *
     * **El nombre del fichero no lleva ningun nombre de persona ni ningun
     * identificador de empleado** (regla dura 21): un adjunto llamado
     * «horas-Lucia-Fernandez.xlsx» divulga a quien se esta mirando con solo ver
     * la bandeja de entrada, y el filtro por empleado de este informe existe.
     */
    public static function filename(PeriodReport $report, string $extension): string
    {
        return 'kronoqr-horas-'.$report->range->isoFrom().'_'.$report->range->isoTo().'.'.$extension;
    }

    /**
     * El instante de generacion en la zona del centro (ADR-040), no en UTC.
     *
     * Lo que se almacena es UTC (regla dura 3) y lo que lee una persona es la
     * hora que vivio: un informe sellado a las 05:12 cuando en el hotel eran las
     * 07:12 parece generado por otro sistema. La zona se escribe al lado para que
     * no haya que adivinarla.
     */
    public static function localInstant(DateTimeImmutable $instant, string $timeZone): string
    {
        return $instant->setTimezone(new DateTimeZone($timeZone))->format('Y-m-d H:i');
    }

    /**
     * @param  array<string, string>  $replacements
     */
    public static function text(string $key, array $replacements = []): string
    {
        $line = Lang::get('reports.'.$key, $replacements);

        if (! \is_string($line) || $line === 'reports.'.$key) {
            throw new RuntimeException('Falta el texto «reports.'.$key.'» en lang/'.Lang::getLocale().'.');
        }

        return $line;
    }
}
