<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Reporting\Domain\ValueObject\JournalCorrection;
use App\Modules\Reporting\Domain\ValueObject\JournalShiftEntry;
use App\Modules\Reporting\Domain\ValueObject\JournalWorkDay;
use App\Modules\Reporting\Domain\ValueObject\ReportedDuration;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use App\Modules\Shared\Domain\Support\SiteWallClock;
use App\Modules\Shared\Domain\Support\UtcInstant;
use App\Modules\Shared\Domain\ValueObject\UtcPrecision;
use DateTimeImmutable;
use Illuminate\Support\Facades\Lang;
use RuntimeException;

/**
 * Que dice el registro propio, en CSV y en PDF, y en que orden (`GET
 * /api/v1/me/export`, RF-ID-05, RL-05; PR19 de la verificacion de la 2.1.0,
 * R1-RV-01 y R1-RV-02 de la reverificacion de la 2.2.0).
 *
 * ## Una sola definicion de columnas y valores para los dos formatos
 *
 * Es el equivalente de {@see PeriodReportLayout} para el registro de una sola
 * persona. Hasta la 2.3.0 el CSV y el PDF tenian cada uno su lista de columnas,
 * su `fill()`, su `duration()` y su conversion de zona, y ya divergian: un
 * estado sin traduccion salia como clave en uno y como valor en el otro, y el
 * PDF no decia en que desfase estaba cada hora. Ahora hay **una** lista,
 * {@see self::COLUMNS}, y **una** funcion por tipo de fila que escribe todas sus
 * celdas. Cada formato solo elige que columnas enseña —el papel no lleva las
 * que solo sirven a una maquina— y con que rotulos.
 *
 * Que filas hay lo decide {@see PersonalRecordRows}, el mismo recorrido para
 * los dos: una por tramo vigente, una por jornada que se quedo sin tramos —no
 * desaparece, regla dura 5— y una por cada correccion, con su autor y su motivo
 * (RN-13).
 *
 * ## Cada hora declara su desfase
 *
 * Las horas se leen en la zona del centro (regla dura 3, ADR-040), y la zona no
 * basta para leerlas: en la madrugada del cambio de hora de octubre las 02:30
 * pasan dos veces. Por eso cada instante lleva al lado su desfase respecto a
 * UTC en ese momento (`UTC+02:00`, `UTC+01:00`), que calcula
 * {@see SiteWallClock}. Un turno de noche que cruza el cambio de hora entra
 * con un desfase y sale con otro, y el documento lo dice en lugar de dejar que
 * su duracion parezca un error.
 *
 * ## Lo que si cambia de un formato a otro
 *
 * - **El tipo de fila.** En el CSV, `TRAMO` y `CORRECCION` son cadenas que
 *   compara una maquina, sin traducir, igual que en la exportacion legal. En el
 *   PDF las lee una persona y se traducen.
 * - **Las columnas `time_zone`, `utc_in` y `utc_out`** solo van en el CSV. El
 *   papel declara la zona una vez en la cabecera y el desfase al lado de cada
 *   hora; la marca almacenada en UTC esta en la descarga en CSV.
 * - **Los rotulos**, que en el CSV dicen ademas «(hora local)».
 *
 * Los origenes, acciones y codigos de motivo se dejan sin traducir en los dos,
 * para que los dos ficheros del mismo registro digan exactamente lo mismo.
 *
 * ## Duraciones en `HH:MM`, nunca decimal
 *
 * Por {@see ReportedDuration}. **El `max(0, ...)` se conserva a proposito.**
 * `ReportedDuration` admite negativos porque en un informe de periodo la
 * desviacion frente a lo contratado puede serlo; en el registro personal no hay
 * desviacion, solo duraciones trabajadas, y un negativo solo podria venir de un
 * dato corrupto. Un `-00:15` en el historico que descarga un empleado es una
 * pregunta que nadie sabe responder: se publica `00:00` y el dato roto se
 * investiga por su lado.
 */
final readonly class PersonalRecordLayout
{
    /**
     * Todas las columnas, en orden. La clave es la de `lang/*\/personal-record.php`:
     * bloque `columns` para el CSV y `pdf.columns` para el PDF.
     *
     * **Escritas una vez.** Los rotulos y las filas de los dos formatos recorren
     * esta misma lista, de modo que una columna nueva no puede quedar rotulada
     * en un sitio y vacia en el otro.
     *
     * @var list<string>
     */
    public const array COLUMNS = [
        'record_type',
        'work_date',
        'time_zone',
        'local_in',
        'offset_in',
        'local_out',
        'offset_out',
        'duration',
        'day_total',
        'status',
        'clock_in_source',
        'clock_out_source',
        'utc_in',
        'utc_out',
        'correction_local_at',
        'correction_offset',
        'correction_author',
        'correction_action',
        'correction_reason',
        'correction_explanation',
    ];

    /**
     * Las columnas de texto libre, que en el PDF si pueden partirse en varias
     * lineas. El resto va en una sola, para que las horas se lean en columna.
     *
     * @var list<string>
     */
    public const array WRAPPING_COLUMNS = ['correction_author', 'correction_explanation'];

    /**
     * Los criterios que se imprimen antes de la tabla, en orden, en los dos
     * formatos.
     *
     * @var list<string>
     */
    public const array CRITERIA = ['entries', 'night', 'open', 'corrections', 'times', 'durations', 'file'];

    /**
     * Las columnas que solo lleva el CSV: las que sirven a una maquina. Ver el
     * docblock de la clase.
     *
     * @var list<string>
     */
    private const array CSV_ONLY_COLUMNS = ['time_zone', 'utc_in', 'utc_out'];

    /**
     * Prefijos de fila del CSV. Sin traducir, igual que en la exportacion legal:
     * quien cruce este fichero con otro tiene que ver la misma cadena en los dos.
     * Lo que se traduce es lo que lee una persona, no lo que compara una maquina.
     */
    private const string CSV_SHIFT_ROW = 'TRAMO';

    private const string CSV_CORRECTION_ROW = 'CORRECCION';

    private function __construct() {}

    /**
     * Las columnas que lleva un formato, en el orden de {@see self::COLUMNS}.
     *
     * @return list<string>
     */
    public static function columns(PersonalRecordFormat $format): array
    {
        if ($format === PersonalRecordFormat::Csv) {
            return self::COLUMNS;
        }

        return array_values(array_filter(
            self::COLUMNS,
            static fn (string $column): bool => ! \in_array($column, self::CSV_ONLY_COLUMNS, true),
        ));
    }

    /**
     * La fila de rotulos de un formato.
     *
     * @return list<string>
     */
    public static function header(PersonalRecordFormat $format): array
    {
        return array_map(
            static fn (string $column): string => self::formatText($format, 'columns.'.$column),
            self::columns($format),
        );
    }

    /**
     * Las posiciones de {@see self::WRAPPING_COLUMNS} dentro de la tabla del PDF.
     *
     * @return list<int>
     */
    public static function wrappingColumnIndexes(): array
    {
        $indexes = [];

        foreach (self::columns(PersonalRecordFormat::Pdf) as $index => $column) {
            if (\in_array($column, self::WRAPPING_COLUMNS, true)) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /**
     * Todas las filas de la tabla de un formato, en el orden del registro y con
     * sus columnas.
     *
     * @return list<list<string>>
     */
    public static function rows(WorkDayJournal $journal, PersonalRecordFormat $format): array
    {
        $columns = self::columns($format);

        return array_map(
            static fn (array $cells): array => array_map(
                static fn (string $column): string => $cells[$column],
                $columns,
            ),
            self::cells($journal, $format),
        );
    }

    /**
     * Cada fila con **todas** las columnas de {@see self::COLUMNS}: lo que el
     * registro afirma antes de que cada formato elija que enseña.
     *
     * @return list<array<string, string>>
     */
    public static function cells(WorkDayJournal $journal, PersonalRecordFormat $format): array
    {
        return PersonalRecordRows::map(
            $journal,
            static fn (JournalWorkDay $day, JournalShiftEntry $entry): array => self::shiftRow($format, $day, $entry),
            static fn (JournalWorkDay $day): array => self::emptyDayRow($format, $day),
            static fn (JournalWorkDay $day, JournalCorrection $correction): array => self::correctionRow($format, $day, $correction),
        );
    }

    /**
     * Minutos trabajados en todo el periodo: la suma de los totales de cada
     * jornada, que a su vez son la suma de sus tramos (RN-06). No se recalcula
     * nada por otro camino: es la misma aritmetica que la pantalla.
     */
    public static function totalMinutes(WorkDayJournal $journal): int
    {
        $total = 0;

        foreach ($journal->days as $day) {
            $total += $day->totalMinutes();
        }

        return $total;
    }

    /**
     * El bloque de cabecera del PDF: rotulo y valor, en orden de lectura. La
     * huella va la ultima porque la plantilla la pinta en monoespaciada.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function metadata(
        WorkDayJournal $journal,
        ?string $holderName,
        string $digest,
        DateTimeImmutable $generatedAt,
    ): array {
        return [
            // Su nombre SI va: es su propio registro y es lo que presenta ante un
            // tercero. Lo que no lo lleva es el nombre del fichero ni el titulo.
            [self::text('holder'), $holderName ?? self::text('holder_unknown')],
            [self::text('period'), self::text('period_value', [
                'from' => $journal->range->isoFrom(),
                'to' => $journal->range->isoTo(),
            ])],
            [self::text('time_zone'), $journal->timeZone],
            [self::text('period_total'), self::duration(self::totalMinutes($journal))],
            [self::text('work_days'), (string) $journal->dayCount()],
            [self::text('generated_at'), self::localInstant($generatedAt, $journal->timeZone)],
            [self::text('legal_basis'), self::legalBasis()],
            [self::text('digest'), $digest],
        ];
    }

    /**
     * Los criterios de un formato, en el orden de {@see self::CRITERIA}.
     *
     * @return list<string>
     */
    public static function criteria(PersonalRecordFormat $format): array
    {
        return array_map(
            static fn (string $criterion): string => self::formatText($format, 'criteria.'.$criterion),
            self::CRITERIA,
        );
    }

    /**
     * El nombre del fichero: el periodo y la extension, **ni nombre ni codigo
     * de empleado** (regla dura 21), igual en los dos formatos. Un adjunto con el
     * nombre de alguien divulga de quien es con solo verlo en una bandeja de
     * descargas.
     */
    public static function filename(WorkDayJournal $journal, string $extension): string
    {
        return 'mi-registro-horario-'.$journal->range->isoFrom().'_'.$journal->range->isoTo().'.'.$extension;
    }

    /**
     * Un instante en la zona del centro, no en UTC (regla dura 3, ADR-040).
     */
    public static function localInstant(DateTimeImmutable $instant, string $timeZone): string
    {
        return new SiteWallClock($timeZone)->minute($instant);
    }

    /**
     * Un texto del PDF (`personal-record.pdf.*`).
     *
     * @param  array<string, string>  $replacements
     */
    public static function text(string $key, array $replacements = []): string
    {
        return self::line('personal-record.pdf.'.$key, $replacements);
    }

    /**
     * Un texto del CSV (`personal-record.*`).
     *
     * @param  array<string, string>  $replacements
     */
    public static function csvText(string $key, array $replacements = []): string
    {
        return self::line('personal-record.'.$key, $replacements);
    }

    /**
     * @return array<string, string>
     */
    private static function shiftRow(PersonalRecordFormat $format, JournalWorkDay $day, JournalShiftEntry $entry): array
    {
        $clock = new SiteWallClock($entry->timeZone);
        $out = $entry->clockedOutAt;

        return self::fill([
            'record_type' => self::shiftRecordType($format),
            'work_date' => $day->workDate,
            'time_zone' => $entry->timeZone,
            'local_in' => $clock->minute($entry->clockedInAt),
            'offset_in' => $clock->offset($entry->clockedInAt),
            'local_out' => $out instanceof DateTimeImmutable ? $clock->minute($out) : '',
            'offset_out' => $out instanceof DateTimeImmutable ? $clock->offset($out) : '',
            'duration' => self::duration($entry->contributedMinutes()),
            'day_total' => self::duration($day->totalMinutes()),
            'status' => self::statusText($entry->status),
            'clock_in_source' => $entry->clockInSource,
            'clock_out_source' => $entry->clockOutSource ?? '',
            'utc_in' => UtcInstant::of($entry->clockedInAt, UtcPrecision::Seconds),
            'utc_out' => $out instanceof DateTimeImmutable ? UtcInstant::of($out, UtcPrecision::Seconds) : '',
        ]);
    }

    /**
     * Una jornada que se quedo sin tramos vigentes: no desaparece (regla dura 5).
     * No tiene horas, asi que tampoco desfase; la zona si, en el CSV.
     *
     * @return array<string, string>
     */
    private static function emptyDayRow(PersonalRecordFormat $format, JournalWorkDay $day): array
    {
        return self::fill([
            'record_type' => self::shiftRecordType($format),
            'work_date' => $day->workDate,
            'time_zone' => $day->timeZone,
            'duration' => self::duration(0),
            'day_total' => self::duration($day->totalMinutes()),
            'status' => self::statusText('none'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function correctionRow(PersonalRecordFormat $format, JournalWorkDay $day, JournalCorrection $correction): array
    {
        $clock = new SiteWallClock($day->timeZone);

        return self::fill([
            'record_type' => $format === PersonalRecordFormat::Csv
                ? self::CSV_CORRECTION_ROW
                : self::text('record_type.correction'),
            'work_date' => $day->workDate,
            'time_zone' => $day->timeZone,
            'correction_local_at' => $clock->minute($correction->performedAt),
            'correction_offset' => $clock->offset($correction->performedAt),
            // El nombre de quien firmo la correccion SI va (RN-13, RL-04): una
            // rectificacion sin autor no explica nada, y es exactamente lo que la
            // persona tiene derecho a poder mirar. Es el unico nombre del
            // documento ademas del suyo.
            'correction_author' => $correction->performedBy->name,
            'correction_action' => $correction->action,
            'correction_reason' => $correction->reasonCode,
            'correction_explanation' => $correction->reasonText ?? '',
        ]);
    }

    private static function shiftRecordType(PersonalRecordFormat $format): string
    {
        return $format === PersonalRecordFormat::Csv ? self::CSV_SHIFT_ROW : self::text('record_type.shift');
    }

    /**
     * Completa las columnas que la fila no usa, para que todas tengan la misma
     * anchura: una tabla con filas de distinta longitud descuadra las celdas y
     * no la abre bien ninguna hoja de calculo.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private static function fill(array $values): array
    {
        $row = [];

        foreach (self::COLUMNS as $column) {
            $row[$column] = $values[$column] ?? '';
        }

        return $row;
    }

    private static function duration(int $minutes): string
    {
        return ReportedDuration::ofMinutes(max(0, $minutes))->toClockText();
    }

    /**
     * La misma traduccion para el mismo dato en los dos formatos, y el mismo
     * criterio ante uno sin traducir: se imprime tal cual. Un estado nuevo no
     * puede dejar a nadie sin su registro.
     */
    private static function statusText(string $status): string
    {
        $key = 'personal-record.status.'.$status;
        $line = Lang::get($key);

        return \is_string($line) && $line !== $key ? $line : $status;
    }

    private static function formatText(PersonalRecordFormat $format, string $key): string
    {
        return $format === PersonalRecordFormat::Csv ? self::csvText($key) : self::text($key);
    }

    private static function legalBasis(): string
    {
        return self::line('personal-record.legal_basis_value');
    }

    /**
     * Un texto que falta es un fallo del programa, no un rotulo vacio: un
     * documento que se presenta ante un tercero no puede salir con
     * `personal-record.pdf.holder` impreso en lugar de «Persona trabajadora».
     *
     * @param  array<string, string>  $replacements
     */
    private static function line(string $key, array $replacements = []): string
    {
        $line = Lang::get($key, $replacements);

        if (! \is_string($line) || $line === $key) {
            throw new RuntimeException('Falta el texto «'.$key.'» en lang/'.Lang::getLocale().'.');
        }

        return $line;
    }
}
