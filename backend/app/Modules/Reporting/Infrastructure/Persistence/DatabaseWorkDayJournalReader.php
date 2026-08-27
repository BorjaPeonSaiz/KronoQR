<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence;

use App\Modules\Reporting\Application\Port\WorkDayJournalReader;
use App\Modules\Reporting\Domain\ValueObject\CorrectionAuthor;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\JournalCorrection;
use App\Modules\Reporting\Domain\ValueObject\JournalShiftEntry;
use App\Modules\Reporting\Domain\ValueObject\JournalWorkDay;
use App\Modules\Reporting\Domain\ValueObject\ShiftMarks;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * {@see WorkDayJournalReader} sobre PostgreSQL (RF-PA-03).
 *
 * ## Cuatro consultas planas, ninguna por fila
 *
 * El detalle de un mes son cuatro `SELECT` con parametros y ni uno mas: las
 * jornadas con actividad, los tramos vigentes, el libro de correcciones y la
 * proyeccion. Se cruzan en PHP por `work_date`. La alternativa —recorrer las
 * jornadas y preguntar por cada una— es el problema N+1 en la pantalla que mas
 * abre quien lleva un turno.
 *
 * Los tres filtros por empleado y rango caen sobre
 * `shift_entries_employee_id_work_date_index` y sobre el UNIQUE de
 * `daily_totals`, que existen desde la migracion inicial precisamente para esto.
 *
 * ## Vigente y no vigente
 *
 * Los tramos se filtran por el predicado de ADR-026 —fuera `voided` y
 * `superseded`—, que es **el mismo** que gobierna el recalculo de `daily_totals`
 * y la restriccion de exclusion del esquema. Las jornadas, en cambio, se
 * enumeran sin filtrar por estado: un dia cuyos tramos se anularon todos sigue
 * existiendo y su historico es justo lo que alguien necesita ver (regla dura 5).
 *
 * ## Las dos marcas de cada fichaje
 *
 * `clock_in_recorded_at` sale de `scan_events`, correlacionando por tramo **y
 * por instante**: un tramo tiene un escaneo que lo abrio y puede tener otro que
 * lo cerro, y lo que los distingue es su `occurred_at`. Un tramo escrito a mano
 * no tiene ninguno y los dos quedan nulos, que es la verdad: nadie lo escaneo.
 *
 * ## Un turno nocturno no se parte
 *
 * El filtro es por `work_date`, que es una columna propia y no una expresion
 * derivada de `clocked_in_at` (RN-05, ADR-006, regla dura 4). Un 22:00 → 06:00
 * sale entero y una sola vez, atribuido al dia en que empezo.
 */
final readonly class DatabaseWorkDayJournalReader implements WorkDayJournalReader
{
    /** ADR-026: los dos estados que no cuentan y no se enseñan como vigentes. */
    private const string CURRENT_ONLY = "status NOT IN ('voided', 'superseded')";

    public function __construct(
        private ConnectionInterface $connection,
        private LoggerInterface $logger,
    ) {}

    public function timeZoneOf(string $employeeUuid): ?string
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select(<<<'SQL'
            SELECT sites.timezone
              FROM employees
              JOIN sites ON sites.id = employees.site_id
             WHERE employees.uuid = ?
        SQL, [$employeeUuid]);

        return $rows === [] ? null : Row::of($rows[0])->string('timezone');
    }

    public function journalFor(string $employeeUuid, string $timeZone, DateRange $range): WorkDayJournal
    {
        $employeeId = $this->internalIdOf($employeeUuid);

        if ($employeeId === null) {
            return new WorkDayJournal($employeeUuid, $timeZone, $range, []);
        }

        $entries = $this->currentEntries($employeeId, $range);
        $corrections = $this->corrections($employeeId, $range);
        $projection = $this->projection($employeeId, $range);

        $days = [];

        foreach ($this->workDates($employeeId, $range) as $workDate) {
            $dayEntries = $entries[$workDate] ?? [];

            $day = new JournalWorkDay(
                workDate: $workDate,
                // La zona del tramo y no la del empleado: un traslado de centro
                // no reescribe donde ocurrieron las jornadas anteriores.
                timeZone: $dayEntries === [] ? $timeZone : $dayEntries[0]->timeZone,
                recalculatedAt: $projection[$workDate]['recalculated_at'] ?? null,
                shiftEntries: $dayEntries,
                corrections: $corrections[$workDate] ?? [],
            );

            $this->warnIfProjectionDiverges($employeeUuid, $day, $projection[$workDate]['total_minutes'] ?? null);

            $days[] = $day;
        }

        return new WorkDayJournal($employeeUuid, $timeZone, $range, $days);
    }

    private function internalIdOf(string $employeeUuid): ?int
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select('SELECT id FROM employees WHERE uuid = ?', [$employeeUuid]);

        return $rows === [] ? null : Row::of($rows[0])->int('id');
    }

    /**
     * Las jornadas **con actividad registrada** del rango, de la mas antigua a
     * la mas reciente.
     *
     * Sin filtrar por estado a proposito, y unida con la proyeccion: un dia cuyos
     * tramos se anularon todos tiene que seguir apareciendo, con la lista vacia y
     * su historico intacto.
     *
     * @return list<string>
     */
    private function workDates(int $employeeId, DateRange $range): array
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select(<<<'SQL'
            SELECT work_date FROM shift_entries
             WHERE employee_id = ? AND work_date BETWEEN ?::date AND ?::date
             UNION
            SELECT work_date FROM daily_totals
             WHERE employee_id = ? AND work_date BETWEEN ?::date AND ?::date
             ORDER BY work_date
        SQL, [
            $employeeId, $range->isoFrom(), $range->isoTo(),
            $employeeId, $range->isoFrom(), $range->isoTo(),
        ]);

        $dates = [];

        foreach ($rows as $row) {
            $dates[] = $this->isoDate(Row::of($row)->string('work_date'));
        }

        return $dates;
    }

    /**
     * Los tramos vigentes del rango, agrupados por jornada.
     *
     * @return array<string, list<JournalShiftEntry>>
     */
    private function currentEntries(int $employeeId, DateRange $range): array
    {
        // En una variable y no como `{$this::CURRENT_ONLY}`: un heredoc no
        // interpola constantes de clase, y lo que ahi parece SQL es un error de
        // sintaxis de PHP.
        $currentOnly = self::CURRENT_ONLY;

        /** @var list<object> $rows */
        $rows = $this->connection->select(<<<SQL
            SELECT se.uuid, se.version, se.status, se.site_id, sites.timezone,
                   se.work_date, se.clocked_in_at, se.clocked_out_at,
                   se.duration_minutes, se.clock_in_source, se.clock_out_source,
                   se.created_at,
                   (SELECT ev.recorded_at FROM scan_events ev
                     WHERE ev.shift_entry_id = se.id AND ev.occurred_at = se.clocked_in_at
                     ORDER BY ev.recorded_at LIMIT 1) AS clock_in_recorded_at,
                   (SELECT ev.recorded_at FROM scan_events ev
                     WHERE ev.shift_entry_id = se.id AND ev.occurred_at = se.clocked_out_at
                     ORDER BY ev.recorded_at LIMIT 1) AS clock_out_recorded_at
              FROM shift_entries se
              JOIN sites ON sites.id = se.site_id
             WHERE se.employee_id = ?
               AND se.work_date BETWEEN ?::date AND ?::date
               AND se.{$currentOnly}
             ORDER BY se.work_date, se.clocked_in_at
        SQL, [$employeeId, $range->isoFrom(), $range->isoTo()]);

        $byDate = [];

        foreach ($rows as $raw) {
            $row = Row::of($raw);
            $byDate[$this->isoDate($row->string('work_date'))][] = new JournalShiftEntry(
                uuid: $row->string('uuid'),
                version: $row->int('version'),
                status: $row->string('status'),
                siteId: $row->int('site_id'),
                timeZone: $row->string('timezone'),
                clockedInAt: $row->instant('clocked_in_at'),
                clockInRecordedAt: $row->nullableInstant('clock_in_recorded_at'),
                clockInSource: $row->string('clock_in_source'),
                clockedOutAt: $row->nullableInstant('clocked_out_at'),
                clockOutRecordedAt: $row->nullableInstant('clock_out_recorded_at'),
                clockOutSource: $row->nullableString('clock_out_source'),
                durationMinutes: $row->nullableInt('duration_minutes'),
                recordedAt: $row->instant('created_at'),
            );
        }

        return $byDate;
    }

    /**
     * El libro de correcciones del rango, agrupado por jornada.
     *
     * Se llega por `shift_entries` y **sin filtrar por estado**: la correccion
     * que anulo un tramo cuelga de una fila que ya no es vigente, y es
     * precisamente la que hay que enseñar.
     *
     * @return array<string, list<JournalCorrection>>
     */
    private function corrections(int $employeeId, DateRange $range): array
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select(<<<'SQL'
            SELECT se.work_date, se.uuid AS shift_entry_uuid,
                   c.action, c.created_at, c.reason_code, c.reason_text,
                   c.before, c.after,
                   u.uuid AS author_uuid, u.name AS author_name
              FROM shift_corrections c
              JOIN shift_entries se ON se.id = c.shift_entry_id
              JOIN users u ON u.id = c.performed_by_user_id
             WHERE se.employee_id = ?
               AND se.work_date BETWEEN ?::date AND ?::date
             ORDER BY se.work_date, c.created_at, c.id
        SQL, [$employeeId, $range->isoFrom(), $range->isoTo()]);

        $byDate = [];

        foreach ($rows as $raw) {
            $row = Row::of($raw);
            $byDate[$this->isoDate($row->string('work_date'))][] = new JournalCorrection(
                shiftEntryUuid: $row->string('shift_entry_uuid'),
                action: $row->string('action'),
                performedAt: $row->instant('created_at'),
                performedBy: new CorrectionAuthor($row->string('author_uuid'), $row->string('author_name')),
                reasonCode: $row->string('reason_code'),
                reasonText: $row->nullableString('reason_text'),
                before: $this->marks($row->json('before')),
                after: $this->marks($row->json('after')),
            );
        }

        return $byDate;
    }

    /**
     * `daily_totals` del rango. De aqui sale **solo** `recalculated_at`, que es
     * informacion sobre la proyeccion; el total lo calcula `JournalWorkDay`
     * sumando los tramos (RN-06). `total_minutes` se lee unicamente para poder
     * avisar si los dos numeros no coinciden.
     *
     * @return array<string, array{total_minutes: int, recalculated_at: DateTimeImmutable|null}>
     */
    private function projection(int $employeeId, DateRange $range): array
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select(<<<'SQL'
            SELECT work_date, total_minutes, recalculated_at
              FROM daily_totals
             WHERE employee_id = ? AND work_date BETWEEN ?::date AND ?::date
        SQL, [$employeeId, $range->isoFrom(), $range->isoTo()]);

        $byDate = [];

        foreach ($rows as $raw) {
            $row = Row::of($raw);
            $byDate[$this->isoDate($row->string('work_date'))] = [
                'total_minutes' => $row->int('total_minutes'),
                'recalculated_at' => $row->nullableInstant('recalculated_at'),
            ];
        }

        return $byDate;
    }

    /**
     * @param  array<string, mixed>|null  $document  `shift_corrections.before` o `.after`.
     */
    private function marks(?array $document): ?ShiftMarks
    {
        if ($document === null) {
            return null;
        }

        $marks = Row::of((object) $document);

        return new ShiftMarks(
            version: $marks->int('version'),
            clockedInAt: $marks->instant('clocked_in_at'),
            clockedOutAt: $marks->nullableInstant('clocked_out_at'),
            workedMinutes: $marks->int('worked_minutes'),
        );
    }

    /**
     * La proyeccion no puede quedar divergente del registro: se recalcula en la
     * misma transaccion que escribe los tramos (RN-06, ADR-007, regla dura 7).
     * Si aun asi no coincidieran, la pantalla enseña la suma de los tramos —que
     * es el registro con valor probatorio— y esto deja la constancia tecnica
     * para la reconciliacion de RF-PR-02.
     *
     * **`warning` y no excepcion**: reventar la consulta dejaria sin ver el
     * registro a la unica persona que puede arreglarlo. Y **sin nombres** (regla
     * dura 21): el empleado se identifica por su UUID.
     */
    private function warnIfProjectionDiverges(string $employeeUuid, JournalWorkDay $day, ?int $projected): void
    {
        if ($projected === null || $projected === $day->totalMinutes()) {
            return;
        }

        $this->logger->warning('reporting.daily_totals_divergence', [
            'employee_uuid' => $employeeUuid,
            'work_date' => $day->workDate,
            'projected_minutes' => $projected,
            'shift_entries_minutes' => $day->totalMinutes(),
        ]);
    }

    /**
     * `DATE` de PostgreSQL, que segun el driver llega como `2026-03-14` o como
     * `2026-03-14 00:00:00`. Las claves de agrupacion tienen que ser la misma
     * cadena en las cuatro consultas o los tramos no encontrarian su jornada.
     */
    private function isoDate(string $value): string
    {
        return substr($value, 0, 10);
    }
}
