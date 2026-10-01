<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Reporting\Domain\ValueObject\JournalCorrection;
use App\Modules\Reporting\Domain\ValueObject\JournalShiftEntry;
use App\Modules\Reporting\Domain\ValueObject\JournalWorkDay;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use DateTimeImmutable;
use DateTimeZone;

/**
 * La huella SHA-256 **del contenido** del registro propio (PR19, RF-ID-05).
 *
 * Mismo criterio que {@see PeriodReportDigest}, y por los mismos motivos: se
 * resume una serializacion canonica de lo que el documento **afirma**, no el
 * binario. No entran el instante de generacion, el formato ni el idioma, asi
 * que dos PDF del mismo registro sacados en dos momentos —o uno en castellano y
 * otro en ingles— llevan la misma huella; y si una correccion cambia una hora,
 * la huella cambia aunque el papel se parezca.
 *
 * ## El formato canonico
 *
 * UTF-8, lineas separadas por `\n`, campos por `\x1F`. Los instantes en UTC con
 * segundos —lo almacenado (regla dura 3)— y las duraciones en minutos enteros:
 * el texto `HH:MM` es presentacion.
 *
 * ```
 * kronoqr-personal-record/1
 * range<US>2026-03-01<US>2026-03-31
 * holder<US>0199…<US>Europe/Madrid
 * day<US>2026-03-14<US>480<US>1
 * shift<US>2026-03-14<US>0199…<US>1<US>closed<US>2026-03-14T05:00:00Z<US>2026-03-14T13:00:00Z<US>480<US>qr_kiosk<US>qr_kiosk
 * correction<US>2026-03-14<US>0199…<US>edit<US>2026-03-15T08:00:00Z<US>Marta Ibáñez<US>forgot_clock_out<US>…
 * ```
 *
 * De la persona entra el **UUID** y no el nombre: el registro es de quien es
 * aunque cambie de apellido. Del autor de una correccion si entra el nombre,
 * porque es lo que el documento imprime como firma de la rectificacion.
 */
final readonly class PersonalRecordDigest
{
    /** Version del formato canonico. Subirla si cambia lo que entra. */
    private const string VERSION = 'kronoqr-personal-record/1';

    /** Separador de unidad de ASCII: no aparece en ningun valor del registro. */
    private const string SEPARATOR = "\x1F";

    private function __construct(public string $canonicalText, public string $sha256) {}

    public static function of(WorkDayJournal $journal): self
    {
        $text = self::canonicalize($journal);

        return new self($text, hash('sha256', $text));
    }

    /** En minusculas y sin separadores, como la del informe por periodo. */
    public function toText(): string
    {
        return $this->sha256;
    }

    private static function canonicalize(WorkDayJournal $journal): string
    {
        $lines = [
            self::VERSION,
            self::line(['range', $journal->range->isoFrom(), $journal->range->isoTo()]),
            self::line(['holder', $journal->employeeUuid, $journal->timeZone]),
        ];

        foreach ($journal->days as $day) {
            $lines[] = self::line(['day', $day->workDate, (string) $day->totalMinutes(), (string) $day->shiftCount()]);

            foreach ($day->shiftEntries as $entry) {
                $lines[] = self::line(self::shift($day, $entry));
            }

            foreach ($day->corrections as $correction) {
                $lines[] = self::line(self::correction($day, $correction));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private static function shift(JournalWorkDay $day, JournalShiftEntry $entry): array
    {
        return [
            'shift',
            $day->workDate,
            $entry->uuid,
            (string) $entry->version,
            $entry->status,
            self::utc($entry->clockedInAt),
            self::utc($entry->clockedOutAt),
            $entry->durationMinutes === null ? '' : (string) $entry->durationMinutes,
            $entry->clockInSource,
            $entry->clockOutSource ?? '',
        ];
    }

    /**
     * @return list<string>
     */
    private static function correction(JournalWorkDay $day, JournalCorrection $correction): array
    {
        return [
            'correction',
            $day->workDate,
            $correction->shiftEntryUuid,
            $correction->action,
            self::utc($correction->performedAt),
            $correction->performedBy->name,
            $correction->reasonCode,
            $correction->reasonText ?? '',
        ];
    }

    private static function utc(?DateTimeImmutable $instant): string
    {
        return $instant instanceof DateTimeImmutable
            ? $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')
            : '';
    }

    /**
     * @param  list<string>  $fields
     */
    private static function line(array $fields): string
    {
        return implode(self::SEPARATOR, $fields);
    }
}
