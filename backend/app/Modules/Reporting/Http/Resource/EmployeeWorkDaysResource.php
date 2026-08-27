<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resource;

use App\Modules\Reporting\Domain\ValueObject\JournalCorrection;
use App\Modules\Reporting\Domain\ValueObject\JournalShiftEntry;
use App\Modules\Reporting\Domain\ValueObject\JournalWorkDay;
use App\Modules\Reporting\Domain\ValueObject\ShiftMarks;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el `200` de `GET /api/v1/employees/{uuid}/workdays`: el esquema
 * `EmployeeWorkDays` del contrato (RF-PA-03).
 *
 * ## Aqui —y solo aqui— se convierte a hora local
 *
 * Regla dura 3: todo instante se guarda y se calcula en UTC, y la conversion a
 * la zona del centro ocurre en la capa de presentacion. Esta clase **es** esa
 * capa. Cada instante viaja dos veces: en UTC, que es el dato, y en la zona del
 * centro con el desplazamiento escrito (`+01:00`), que es lo que se pinta. Que
 * las dos salgan del servidor no es redundancia: si el navegador hiciera la
 * conversion, la haria con la zona del dispositivo, y una tablet mal configurada
 * o un empleado consultando desde otro pais convertirian mal las horas de un
 * registro con valor legal.
 *
 * **La zona la pone cada tramo, no la jornada.** Un traslado de centro no
 * reescribe donde ocurrieron las jornadas anteriores, asi que un tramo fichado
 * en Canarias se presenta en `Atlantic/Canary` aunque la persona trabaje hoy en
 * Madrid.
 *
 * ## El total no se calcula aqui
 *
 * `total_minutes`, `shift_count`, `has_open_shift` y `has_incident` se leen de
 * {@see JournalWorkDay}, que los deriva de sus tramos (RN-06). Recalcularlos en
 * la serializacion crearia una segunda forma de contar lo mismo, y dos formas de
 * contar lo mismo acaban discrepando: entonces el panel pintaria un total que no
 * cuadra con las filas que tiene debajo.
 *
 * ## Ningun nombre de empleado sale por aqui
 *
 * Solo `employee_uuid`, como en el resto de la API (regla dura 21). El unico
 * nombre de persona de esta respuesta es el de **quien firmo una correccion**,
 * que RN-13 obliga a poder enseñar: una correccion sin autor no explica nada ante
 * una inspeccion.
 *
 * @property-read WorkDayJournal $resource
 */
final class EmployeeWorkDaysResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WorkDayJournal $journal */
        $journal = $this->resource;

        $days = [];

        foreach ($journal->days as $day) {
            $days[] = self::day($day);
        }

        return [
            'employee_uuid' => $journal->employeeUuid,
            'time_zone' => $journal->timeZone,
            // El rango **resuelto**, que puede no ser el que se pidio: sin `from`
            // ni `to` son los 31 dias que terminan hoy en la zona del centro, y
            // el cliente tiene que saber que ventana esta mirando.
            'from' => $journal->range->isoFrom(),
            'to' => $journal->range->isoTo(),
            'data' => $days,
            'meta' => ['total' => $journal->dayCount()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function day(JournalWorkDay $day): array
    {
        $entries = [];

        foreach ($day->shiftEntries as $entry) {
            $entries[] = self::entry($entry);
        }

        $corrections = [];

        foreach ($day->corrections as $correction) {
            $corrections[] = self::correction($correction, $day->timeZone);
        }

        return [
            'work_date' => $day->workDate,
            'time_zone' => $day->timeZone,
            'total_minutes' => $day->totalMinutes(),
            'shift_count' => $day->shiftCount(),
            'has_open_shift' => $day->hasOpenShift(),
            'has_incident' => $day->hasIncident(),
            'recalculated_at' => self::utcOrNull($day->recalculatedAt),
            'shift_entries' => $entries,
            'corrections' => $corrections,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function entry(JournalShiftEntry $entry): array
    {
        return [
            'uuid' => $entry->uuid,
            'version' => $entry->version,
            'status' => $entry->status,
            'site_id' => $entry->siteId,
            'time_zone' => $entry->timeZone,
            'clocked_in_at' => self::utc($entry->clockedInAt),
            'clocked_in_at_local' => self::local($entry->clockedInAt, $entry->timeZone),
            'clocked_in_recorded_at' => self::utcOrNull($entry->clockInRecordedAt),
            'clock_in_source' => $entry->clockInSource,
            'clocked_out_at' => self::utcOrNull($entry->clockedOutAt),
            'clocked_out_at_local' => self::localOrNull($entry->clockedOutAt, $entry->timeZone),
            'clocked_out_recorded_at' => self::utcOrNull($entry->clockOutRecordedAt),
            'clock_out_source' => $entry->clockOutSource,
            'duration_minutes' => $entry->durationMinutes,
            'recorded_at' => self::utc($entry->recordedAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function correction(JournalCorrection $correction, string $timeZone): array
    {
        return [
            'shift_entry_uuid' => $correction->shiftEntryUuid,
            'action' => $correction->action,
            'performed_at' => self::utc($correction->performedAt),
            'performed_at_local' => self::local($correction->performedAt, $timeZone),
            'performed_by' => [
                'uuid' => $correction->performedBy->uuid,
                'name' => $correction->performedBy->name,
            ],
            'reason_code' => $correction->reasonCode,
            'reason_text' => $correction->reasonText,
            'before' => self::marks($correction->before),
            'after' => self::marks($correction->after),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function marks(?ShiftMarks $marks): ?array
    {
        if (! $marks instanceof ShiftMarks) {
            return null;
        }

        return [
            'version' => $marks->version,
            'clocked_in_at' => self::utc($marks->clockedInAt),
            'clocked_out_at' => self::utcOrNull($marks->clockedOutAt),
            'worked_minutes' => $marks->workedMinutes,
        ];
    }

    /**
     * ISO-8601 en UTC con microsegundos, el esquema `UtcTimestamp`.
     *
     * Los seis decimales no son adorno: `shift_entries` guarda con precision de
     * microsegundo y una respuesta redondeada al segundo no seria la hora
     * escrita, que en un registro con valor legal no es aceptable.
     */
    private static function utc(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function utcOrNull(?DateTimeImmutable $instant): ?string
    {
        return $instant instanceof DateTimeImmutable ? self::utc($instant) : null;
    }

    /**
     * El **mismo** instante en la zona del centro, con el desplazamiento
     * explicito: el esquema `LocalTimestamp`. No sustituye al de UTC, viaja
     * ademas.
     */
    private static function local(DateTimeImmutable $instant, string $timeZone): string
    {
        return $instant->setTimezone(new DateTimeZone($timeZone))->format('Y-m-d\TH:i:s.uP');
    }

    private static function localOrNull(?DateTimeImmutable $instant, string $timeZone): ?string
    {
        return $instant instanceof DateTimeImmutable ? self::local($instant, $timeZone) : null;
    }
}
