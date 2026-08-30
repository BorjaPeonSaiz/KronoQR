<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use App\Modules\Attendance\Application\Port\SiteCalendar;
use App\Modules\Attendance\Application\Port\WorkDayLedger;
use App\Modules\Attendance\Domain\Model\ShiftEntry as ShiftEntryEntity;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryStatus;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Lectura en bloque del registro horario para la revision diaria (RF-PR-01).
 *
 * **Solo lee.** No hay aqui ningun `save()`, ningun `update()` y ninguna forma
 * de cerrar un tramo: es la garantia estructural de RN-08 —«nunca se cierra
 * automaticamente sin intervencion humana»— y por eso es un puerto aparte de
 * {@see EloquentWorkDayRepository} y no dos metodos mas dentro de el.
 *
 * **Dos consultas por bloque, no una por jornada.** Se traen los tramos de la
 * ventana de una vez y se agrupan en memoria; los UUID de empleado se resuelven
 * con una sola consulta a `employees` sobre los identificadores que han
 * aparecido. Un `findWorkDayFor()` por jornada habria costado tres consultas por
 * dia y por persona: sobre una plantilla de doscientas y una ventana de siete
 * dias, cuatro mil consultas para leer lo mismo.
 *
 * **La zona horaria entra por `SiteCalendar`** y se memoriza por centro: sin
 * ella no se puede construir un `WorkDate`, porque una fecha civil sin la zona en
 * la que es civil no dice a que dia pertenece un turno de noche (RN-05).
 *
 * Como en el repositorio, la unica columna de otro modulo que se lee es
 * `employees.uuid`, y solo porque `shift_entries.employee_id` es una clave
 * foranea a esa tabla.
 */
final class EloquentWorkDayLedger implements WorkDayLedger
{
    /** @var array<int, DateTimeZone> */
    private array $timezones = [];

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly SiteCalendar $calendar,
    ) {}

    public function openWorkDays(): array
    {
        // El indice unico parcial `one_open_shift_per_employee` cubre justo esta
        // consulta: entran solo las filas vigentes sin salida.
        $open = ShiftEntry::query()
            ->whereNull('clocked_out_at')
            ->whereNotIn('status', self::historicalStatuses())
            ->orderBy('employee_id')
            ->orderBy('clocked_in_at')
            ->get()
            ->all();

        if ($open === []) {
            return [];
        }

        // La jornada de un turno abierto puede tener ademas tramos ya cerrados
        // —una pausa por la manana y el turno de tarde sin cerrar— y el agregado
        // tiene que llegar completo: RN-11 se evalua sobre la SUMA del dia.
        $rows = ShiftEntry::query()
            ->whereNotIn('status', self::historicalStatuses())
            ->where(function ($query) use ($open): void {
                foreach ($open as $entry) {
                    $query->orWhere(function ($nested) use ($entry): void {
                        $nested->where('employee_id', $entry->employee_id)
                            ->where('work_date', $entry->work_date->format('Y-m-d'));
                    });
                }
            })
            ->orderBy('employee_id')
            ->orderBy('work_date')
            ->orderBy('clocked_in_at')
            ->orderBy('id')
            ->get()
            ->all();

        return $this->groupIntoWorkDays(array_values($rows));
    }

    public function workDaysBetween(WorkDate $from, WorkDate $to): array
    {
        $rows = ShiftEntry::query()
            ->whereBetween('work_date', [$from->isoDate, $to->isoDate])
            ->whereNotIn('status', self::historicalStatuses())
            ->orderBy('employee_id')
            ->orderBy('work_date')
            ->orderBy('clocked_in_at')
            ->orderBy('id')
            ->get()
            ->all();

        return $this->groupIntoWorkDays(array_values($rows));
    }

    public function lastClockOutBefore(string $employeeUuid, DateTimeImmutable $instant): ?DateTimeImmutable
    {
        $employeeId = $this->employeeIdOf($employeeUuid);

        if ($employeeId === null) {
            return null;
        }

        $lastClockOut = ShiftEntry::query()
            ->where('employee_id', $employeeId)
            ->whereNotNull('clocked_out_at')
            ->whereNotIn('status', self::historicalStatuses())
            ->where('clocked_out_at', '<', $instant->format('Y-m-d H:i:s.uP'))
            ->orderByDesc('clocked_out_at')
            ->value('clocked_out_at');

        return $lastClockOut instanceof DateTimeInterface ? self::toUtc($lastClockOut) : null;
    }

    /**
     * Agrupa las filas en agregados por empleado y fecha.
     *
     * `reconstitute()` vuelve a comprobar las invariantes, igual que en el
     * repositorio: si una importacion dejo dos turnos abiertos del mismo dia,
     * esto falla aqui en vez de producir una revision que suma minutos
     * imposibles.
     *
     * @param  list<ShiftEntry>  $rows
     * @return list<WorkDay>
     */
    private function groupIntoWorkDays(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $employeeUuids = $this->employeeUuidsOf($rows);
        $grouped = [];

        foreach ($rows as $row) {
            $employeeUuid = $employeeUuids[$row->employee_id] ?? null;

            if ($employeeUuid === null) {
                // Un tramo cuyo empleado ya no esta es una inconsistencia de
                // datos que no se resuelve con una incidencia sobre nadie.
                continue;
            }

            $workDate = WorkDate::fromIsoDate($row->work_date->format('Y-m-d'), $this->timezoneOf($row->site_id));
            $key = $employeeUuid.'|'.$workDate->isoDate;

            $grouped[$key] ??= [
                'employee_uuid' => $employeeUuid,
                'site_id' => $row->site_id,
                'work_date' => $workDate,
                'entries' => [],
            ];

            $grouped[$key]['entries'][] = self::toEntity($row, $employeeUuid, $workDate);
        }

        $workDays = [];

        foreach ($grouped as $group) {
            $workDays[] = WorkDay::reconstitute(
                $group['employee_uuid'],
                $group['site_id'],
                $group['work_date'],
                $group['entries'],
            );
        }

        return $workDays;
    }

    /**
     * Los UUID publicos de los empleados que aparecen en las filas, en una sola
     * consulta.
     *
     * @param  list<ShiftEntry>  $rows
     * @return array<int, string>
     */
    private function employeeUuidsOf(array $rows): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (ShiftEntry $row): int => $row->employee_id,
            $rows,
        )));

        /** @var list<object{id: int, uuid: string}> $employees */
        $employees = $this->connection->table('employees')
            ->select(['id', 'uuid'])
            ->whereIn('id', $ids)
            ->get()
            ->all();

        $byId = [];

        foreach ($employees as $employee) {
            $byId[$employee->id] = $employee->uuid;
        }

        return $byId;
    }

    private function employeeIdOf(string $employeeUuid): ?int
    {
        $id = $this->connection->table('employees')->where('uuid', $employeeUuid)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function timezoneOf(int $siteId): DateTimeZone
    {
        return $this->timezones[$siteId] ??= $this->calendar->timezoneOf($siteId)
            ?? throw new RuntimeException('Site '.$siteId.' has no timezone; RN-05 cannot be resolved.');
    }

    private static function toEntity(ShiftEntry $row, string $employeeUuid, WorkDate $workDate): ShiftEntryEntity
    {
        $clockOutSource = $row->clock_out_source;

        return ShiftEntryEntity::reconstitute(
            $row->uuid,
            $employeeUuid,
            $workDate,
            self::toUtc($row->clocked_in_at),
            $row->clocked_out_at === null ? null : self::toUtc($row->clocked_out_at),
            ScanOrigin::from($row->clock_in_source),
            $clockOutSource === null ? null : ScanOrigin::from($clockOutSource),
            ShiftEntryStatus::from($row->status),
            $row->version,
        );
    }

    /**
     * La columna es `TIMESTAMPTZ` y PostgreSQL la devuelve en la zona de la
     * sesion; el dominio solo acepta UTC (RN-04, regla dura 3).
     */
    private static function toUtc(DateTimeInterface $value): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * El predicado de ADR-026: ni anulados ni sustituidos.
     *
     * @return list<string>
     */
    private static function historicalStatuses(): array
    {
        return [ShiftEntryStatus::VOIDED->value, ShiftEntryStatus::SUPERSEDED->value];
    }
}
