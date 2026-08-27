<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Response;

use App\Modules\Reporting\Domain\ValueObject\JournalCorrection;
use App\Modules\Reporting\Domain\ValueObject\JournalShiftEntry;
use App\Modules\Reporting\Domain\ValueObject\JournalWorkDay;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use App\Modules\Shared\Infrastructure\Export\CsvDialect;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El historico propio como fichero CSV (`GET /api/v1/me/export`, RF-ID-05,
 * RL-05).
 *
 * ## Por que no reutiliza el escritor de la exportacion legal
 *
 * `Compliance\Infrastructure\Export\CsvLegalExportWriter` —nombrado en prosa
 * porque `Reporting` no puede importarlo (doc 02 §1.6)— escribe otro documento:
 * veintiocho columnas, alcance de plantilla, manifiesto con base legal, asiento
 * en `audit_log`, escritura a fichero temporal para que la traza se escriba antes
 * de entregar nada, y un origen propio que recorre un cursor de servidor.
 * Reutilizarlo habria significado abrir una frontera entre modulos y arrastrar
 * toda esa maquinaria para servir el mes de una sola persona.
 *
 * Lo que **si** se comparte, y ahora de verdad y no de palabra, son las
 * decisiones de codificacion: BOM, punto y coma, entrecomillado del RFC 4180,
 * sin escapado propietario y `\r\n` como fin de linea. Viven en
 * {@see CsvDialect} y las escriben sus dos metodos, de modo que los dos ficheros
 * no puedan volver a divergir. Durante un tiempo lo hicieron —este salia con
 * `\r\n` y el de la Inspeccion con `\n`— porque este docblock decia que
 * reutilizaba esas decisiones cuando lo unico que reutilizaba era el
 * razonamiento.
 *
 * La decision de formato que **no** esta alli, porque es de contenido y no de
 * bytes, es **`HH:MM` como texto, nunca decimal**: «7,5» no es una hora y media,
 * es una cifra que hay que interpretar. `07:30` no se interpreta. Es el mismo
 * listón que la exportacion legal, porque el numero tiene las mismas
 * consecuencias.
 *
 * ## Y una que es propia de este fichero: no hay nadie mas dentro
 *
 * El alcance es exactamente el del token que lo pidio. No hay columna de
 * empleado porque solo hay uno, y el nombre del fichero tampoco lo lleva.
 *
 * ## Streaming, y aqui si se puede
 *
 * A diferencia de la exportacion legal, esto no se escribe a un temporal para
 * poder auditar antes de entregar: no hay asiento que escribir (RS-05 habla de
 * terceros) y el volumen esta acotado por el techo de 366 dias de `DateRange`.
 * Se emite directamente, sin dejar en disco un fichero con el registro horario
 * de alguien.
 *
 * ## Los rotulos salen de `lang/` y no de aqui
 *
 * `lang/{es,en}/personal-record.php`. El idioma del documento es configuracion
 * de la instalacion (regla dura 13, ADR-017): un escritor con los rotulos
 * incrustados obligaria a tocar el repositorio para vender a un cliente que
 * trabaja en ingles.
 */
final readonly class PersonalRecordCsv
{
    /**
     * Las columnas, en orden. La clave es la de `lang/*`.
     *
     * **Escritas una vez.** La cabecera y cada fila recorren esta misma lista,
     * de modo que una columna nueva no puede quedar rotulada en un sitio y vacia
     * en el otro.
     *
     * @var list<string>
     */
    private const array COLUMNS = [
        'record_type',
        'work_date',
        'time_zone',
        'local_in',
        'local_out',
        'duration',
        'day_total',
        'status',
        'clock_in_source',
        'clock_out_source',
        'utc_in',
        'utc_out',
        'correction_local_at',
        'correction_author',
        'correction_action',
        'correction_reason',
        'correction_explanation',
    ];

    /** Los criterios que se imprimen antes de la tabla, en orden. */
    private const array CRITERIA = ['entries', 'night', 'open', 'corrections', 'times', 'durations', 'file'];

    /**
     * Prefijos de fila. En ingles y sin traducir, igual que en la exportacion
     * legal: quien cruce este fichero con otro tiene que ver la misma cadena en
     * los dos. Lo que se traduce es lo que lee una persona, no lo que compara
     * una maquina.
     */
    private const string SHIFT_ROW = 'TRAMO';

    private const string CORRECTION_ROW = 'CORRECCION';

    public static function respond(WorkDayJournal $journal): StreamedResponse
    {
        $filename = 'mi-registro-horario-'.$journal->range->isoFrom().'_'.$journal->range->isoTo().'.csv';

        return new StreamedResponse(
            static function () use ($journal): void {
                $handle = fopen('php://output', 'wb');

                if ($handle === false) {
                    return;
                }

                // La marca de orden de bytes va antes que nada: es lo que le dice
                // a la hoja de calculo que esto es UTF-8.
                CsvDialect::writeByteOrderMark($handle);

                self::writeHeader($handle, $journal);

                foreach ($journal->days as $day) {
                    self::writeDay($handle, $day);
                }

                fclose($handle);
            },
            200,
            [
                'Content-Type' => CsvDialect::CONTENT_TYPE,
                'Content-Disposition' => 'attachment; filename='.$filename,
                // El cuerpo es el registro horario de una persona: ni un proxy
                // ni un navegador compartido pueden guardarlo.
                'Cache-Control' => 'no-store, private',
            ],
        );
    }

    /**
     * Los criterios de inclusion y, tras una linea en blanco, los rotulos.
     *
     * La linea en blanco es lo que permite que una hoja de calculo reconozca la
     * tabla al seleccionarla, y los criterios son lo que hace que el fichero se
     * explique solo cuando alguien lo abra dos años despues.
     *
     * @param  resource  $handle
     */
    private static function writeHeader($handle, WorkDayJournal $journal): void
    {
        self::row($handle, [self::text('title')]);
        self::row($handle, [self::label('period'), self::text('period_value', [
            'from' => $journal->range->isoFrom(),
            'to' => $journal->range->isoTo(),
        ])]);
        self::row($handle, [self::label('time_zone'), $journal->timeZone]);
        self::row($handle, [self::label('legal_basis'), self::text('legal_basis_value')]);

        foreach (self::CRITERIA as $index => $criterion) {
            self::row($handle, [
                $index === 0 ? self::label('criteria') : '',
                self::text('criteria.'.$criterion),
            ]);
        }

        self::row($handle, []);
        self::row($handle, array_map(
            static fn (string $column): string => self::text('columns.'.$column),
            self::COLUMNS,
        ));
    }

    /**
     * @param  resource  $handle
     */
    private static function writeDay($handle, JournalWorkDay $day): void
    {
        $total = self::duration($day->totalMinutes());

        foreach ($day->shiftEntries as $entry) {
            self::row($handle, self::shiftRow($day, $entry, $total));
        }

        // Una jornada sin tramos vigentes **no desaparece** (regla dura 5): si
        // se anularon todos, sigue apareciendo con su total a cero y su
        // historico intacto. Ocultarla haria desaparecer del fichero justo el
        // dia sobre el que alguien quiere preguntar.
        if ($day->shiftEntries === []) {
            self::row($handle, self::emptyDayRow($day, $total));
        }

        foreach ($day->corrections as $correction) {
            self::row($handle, self::correctionRow($day, $correction));
        }
    }

    /**
     * @return array<string, string>
     */
    private static function shiftRow(JournalWorkDay $day, JournalShiftEntry $entry, string $dayTotal): array
    {
        return self::fill([
            'record_type' => self::SHIFT_ROW,
            'work_date' => $day->workDate,
            'time_zone' => $entry->timeZone,
            'local_in' => self::local($entry->clockedInAt, $entry->timeZone),
            'local_out' => self::localOrEmpty($entry->clockedOutAt, $entry->timeZone),
            'duration' => self::duration($entry->contributedMinutes()),
            'day_total' => $dayTotal,
            'status' => self::text('status.'.$entry->status),
            'clock_in_source' => $entry->clockInSource,
            'clock_out_source' => $entry->clockOutSource ?? '',
            'utc_in' => self::utc($entry->clockedInAt),
            'utc_out' => self::utcOrEmpty($entry->clockedOutAt),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function emptyDayRow(JournalWorkDay $day, string $dayTotal): array
    {
        return self::fill([
            'record_type' => self::SHIFT_ROW,
            'work_date' => $day->workDate,
            'time_zone' => $day->timeZone,
            'duration' => self::duration(0),
            'day_total' => $dayTotal,
            'status' => self::text('status.none'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function correctionRow(JournalWorkDay $day, JournalCorrection $correction): array
    {
        return self::fill([
            'record_type' => self::CORRECTION_ROW,
            'work_date' => $day->workDate,
            'time_zone' => $day->timeZone,
            'correction_local_at' => self::local($correction->performedAt, $day->timeZone),
            // El nombre de quien firmo la correccion SI va (RN-13, RL-04): una
            // rectificacion sin autor no explica nada, y es exactamente lo que la
            // persona tiene derecho a poder mirar. Es el unico nombre de este
            // fichero ademas del suyo, que ni siquiera aparece.
            'correction_author' => $correction->performedBy->name,
            'correction_action' => $correction->action,
            'correction_reason' => $correction->reasonCode,
            'correction_explanation' => $correction->reasonText ?? '',
        ]);
    }

    /**
     * Completa las columnas que la fila no usa, para que todas tengan la misma
     * anchura. Una tabla con filas de distinta longitud no la abre bien ninguna
     * hoja de calculo.
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

    /**
     * Duracion en `HH:MM`, nunca decimal. Ver el docblock de la clase.
     */
    private static function duration(int $minutes): string
    {
        return \sprintf('%02d:%02d', intdiv(max(0, $minutes), 60), max(0, $minutes) % 60);
    }

    private static function local(DateTimeImmutable $instant, string $timeZone): string
    {
        return $instant->setTimezone(new DateTimeZone($timeZone))->format('Y-m-d H:i');
    }

    private static function localOrEmpty(?DateTimeImmutable $instant, string $timeZone): string
    {
        return $instant instanceof DateTimeImmutable ? self::local($instant, $timeZone) : '';
    }

    private static function utc(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function utcOrEmpty(?DateTimeImmutable $instant): string
    {
        return $instant instanceof DateTimeImmutable ? self::utc($instant) : '';
    }

    /**
     * @param  resource  $handle
     * @param  array<array-key, string>  $values
     */
    private static function row($handle, array $values): void
    {
        // Delimitador, entrecomillado, escapado y fin de linea los pone el
        // dialecto: aqui no queda ninguna decision de codificacion que pueda
        // separarse de la del fichero que se entrega a la Inspeccion.
        CsvDialect::writeRow($handle, $values);
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private static function text(string $key, array $replacements = []): string
    {
        $text = Lang::get('personal-record.'.$key, $replacements);

        return \is_string($text) ? $text : $key;
    }

    private static function label(string $key): string
    {
        return self::text('header.'.$key);
    }
}
