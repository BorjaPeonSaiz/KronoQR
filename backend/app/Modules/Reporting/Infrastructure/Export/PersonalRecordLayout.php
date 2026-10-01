<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Reporting\Domain\ValueObject\JournalCorrection;
use App\Modules\Reporting\Domain\ValueObject\JournalShiftEntry;
use App\Modules\Reporting\Domain\ValueObject\JournalWorkDay;
use App\Modules\Reporting\Domain\ValueObject\ReportedDuration;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Lang;
use RuntimeException;

/**
 * Que dice el PDF del registro propio y en que orden (`GET /api/v1/me/export
 * ?format=pdf`, RF-ID-05, RL-05; PR19 de la verificacion de la 2.1.0).
 *
 * Es el equivalente de {@see PeriodReportLayout} para el registro de una sola
 * persona: decide columnas, filas, cabecera de metadatos, criterios y nombre del
 * fichero, y no compone nada. El cuerpo y el sello los pintan **las mismas
 * plantillas** que el informe por periodo; ver {@see PersonalRecordPdfWriter}.
 *
 * ## Las mismas filas que el CSV, menos lo que solo sirve a una maquina
 *
 * Una fila por tramo vigente, una por jornada que se quedo sin tramos —no
 * desaparece, regla dura 5— y una por cada correccion, con su autor y su motivo
 * (RN-13). Lo que no esta es la marca en UTC ni la zona de cada fila: el papel
 * se lee en la hora del centro, que se declara una vez en la cabecera, y la
 * descarga en CSV sigue llevando la marca almacenada para quien la necesite.
 *
 * **Los tipos de fila se traducen**, al contrario que en el CSV: alli `TRAMO` y
 * `CORRECCION` son cadenas que compara una maquina; aqui las lee una persona.
 * Los origenes, acciones y codigos de motivo se dejan como en el CSV, para que
 * los dos ficheros del mismo registro digan exactamente lo mismo.
 *
 * ## Duraciones en `HH:MM`, nunca decimal
 *
 * Por {@see ReportedDuration}, con el mismo `max(0, ...)` que el CSV y por el
 * mismo motivo: en el registro personal no hay desviaciones, solo duraciones
 * trabajadas, y un negativo solo podria venir de un dato corrupto.
 */
final readonly class PersonalRecordLayout
{
    /**
     * Las columnas, en orden. La clave es la de `lang/*\/personal-record.php`,
     * bloque `pdf.columns`.
     *
     * @var list<string>
     */
    public const array COLUMNS = [
        'record_type',
        'work_date',
        'local_in',
        'local_out',
        'duration',
        'day_total',
        'status',
        'clock_in_source',
        'clock_out_source',
        'correction_local_at',
        'correction_author',
        'correction_action',
        'correction_reason',
        'correction_explanation',
    ];

    /**
     * Las columnas de texto libre, que si pueden partirse en varias lineas. El
     * resto va en una sola, para que las horas se lean en columna.
     *
     * @var list<string>
     */
    public const array WRAPPING_COLUMNS = ['correction_author', 'correction_explanation'];

    /** Los criterios que se imprimen antes de la tabla, en orden. */
    private const array CRITERIA = ['entries', 'night', 'open', 'corrections', 'times', 'durations', 'file'];

    private function __construct() {}

    /**
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
     * Las posiciones de {@see self::WRAPPING_COLUMNS} dentro de la tabla.
     *
     * @return list<int>
     */
    public static function wrappingColumnIndexes(): array
    {
        $indexes = [];

        foreach (self::COLUMNS as $index => $column) {
            if (\in_array($column, self::WRAPPING_COLUMNS, true)) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /**
     * Todas las filas de la tabla, en el orden del registro.
     *
     * @return list<list<string>>
     */
    public static function rows(WorkDayJournal $journal): array
    {
        $rows = [];

        foreach ($journal->days as $day) {
            $total = self::duration($day->totalMinutes());

            foreach ($day->shiftEntries as $entry) {
                $rows[] = self::shiftRow($day, $entry, $total);
            }

            // Una jornada sin tramos vigentes no desaparece (regla dura 5): si se
            // anularon todos, sigue con su total a cero y su historico intacto.
            if ($day->shiftEntries === []) {
                $rows[] = self::fill([
                    'record_type' => self::text('record_type.shift'),
                    'work_date' => $day->workDate,
                    'duration' => self::duration(0),
                    'day_total' => $total,
                    'status' => self::statusText('none'),
                ]);
            }

            foreach ($day->corrections as $correction) {
                $rows[] = self::correctionRow($day, $correction);
            }
        }

        return $rows;
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
     * El bloque de cabecera: rotulo y valor, en orden de lectura. La huella va
     * la ultima porque la plantilla la pinta en monoespaciada.
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
     * @return list<string>
     */
    public static function criteria(): array
    {
        return array_map(
            static fn (string $criterion): string => self::text('criteria.'.$criterion),
            self::CRITERIA,
        );
    }

    /**
     * El nombre del fichero: el periodo y la extension, **ni nombre ni codigo
     * de empleado** (regla dura 21), igual que el CSV. Un adjunto con el nombre
     * de alguien divulga de quien es con solo verlo en una bandeja de descargas.
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
        return $instant->setTimezone(new DateTimeZone($timeZone))->format('Y-m-d H:i');
    }

    /**
     * @param  array<string, string>  $replacements
     */
    public static function text(string $key, array $replacements = []): string
    {
        return self::line('personal-record.pdf.'.$key, $replacements);
    }

    /**
     * @return list<string>
     */
    private static function shiftRow(JournalWorkDay $day, JournalShiftEntry $entry, string $dayTotal): array
    {
        return self::fill([
            'record_type' => self::text('record_type.shift'),
            'work_date' => $day->workDate,
            'local_in' => self::localInstant($entry->clockedInAt, $entry->timeZone),
            'local_out' => $entry->clockedOutAt instanceof DateTimeImmutable
                ? self::localInstant($entry->clockedOutAt, $entry->timeZone)
                : '',
            'duration' => self::duration($entry->contributedMinutes()),
            'day_total' => $dayTotal,
            'status' => self::statusText($entry->status),
            'clock_in_source' => $entry->clockInSource,
            'clock_out_source' => $entry->clockOutSource ?? '',
        ]);
    }

    /**
     * @return list<string>
     */
    private static function correctionRow(JournalWorkDay $day, JournalCorrection $correction): array
    {
        return self::fill([
            'record_type' => self::text('record_type.correction'),
            'work_date' => $day->workDate,
            'correction_local_at' => self::localInstant($correction->performedAt, $day->timeZone),
            // El nombre de quien firmo la correccion SI va (RN-13, RL-04): una
            // rectificacion sin autor no explica nada.
            'correction_author' => $correction->performedBy->name,
            'correction_action' => $correction->action,
            'correction_reason' => $correction->reasonCode,
            'correction_explanation' => $correction->reasonText ?? '',
        ]);
    }

    /**
     * Completa las columnas que la fila no usa: una tabla con filas de distinta
     * anchura descuadra las celdas.
     *
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private static function fill(array $values): array
    {
        $row = [];

        foreach (self::COLUMNS as $column) {
            $row[] = $values[$column] ?? '';
        }

        return $row;
    }

    private static function duration(int $minutes): string
    {
        return ReportedDuration::ofMinutes(max(0, $minutes))->toClockText();
    }

    /**
     * Los estados son los del CSV: la misma traduccion para el mismo dato, y el
     * mismo criterio ante uno sin traducir —se imprime tal cual—. Un estado
     * nuevo no puede dejar a nadie sin su registro.
     */
    private static function statusText(string $status): string
    {
        $key = 'personal-record.status.'.$status;
        $line = Lang::get($key);

        return \is_string($line) && $line !== $key ? $line : $status;
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
