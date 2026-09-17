<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use App\Modules\Attendance\Application\Port\RecordedScan;
use App\Modules\Attendance\Application\Port\ScanLog;
use App\Modules\Attendance\Application\Port\ScanRecord;
use App\Modules\Attendance\Application\Port\ScanResult;
use App\Modules\Attendance\Domain\ValueObject\AcceptedScan;
use App\Modules\Attendance\Domain\ValueObject\ClockingAction;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use stdClass;

/**
 * `scan_events` sobre PostgreSQL: el registro de **todo** escaneo.
 *
 * ## La idempotencia vive en esta clase, y en una sola linea
 *
 * `insertOrIgnore` compila a `INSERT ... ON CONFLICT DO NOTHING` y devuelve el
 * numero de filas escritas. Cero significa que ese `scan_id` ya estaba, y lo
 * decide el indice `scan_events_scan_id_unique` (regla dura 8).
 *
 * **No hay ningun `SELECT` previo**, y no es una preferencia de estilo: entre
 * una consulta que pregunta si el escaneo existe y la insercion que lo escribe
 * cabe otra peticion con el mismo identificador. Bajo el pico de un cambio de
 * turno —treinta personas fichando en el mismo minuto, con la cola offline
 * reintentando— esa carrera produce tramos duplicados en el registro legal de
 * alguien. `ON CONFLICT` no tiene ventana: PostgreSQL **espera** a la
 * transaccion que esta insertando la misma clave y, cuando confirma, devuelve
 * cero. Nunca dos.
 *
 * ## Las dos lecturas del camino de fichaje caben en el mismo indice
 *
 * `acceptedScansAdjacentTo()` mide la ventana de RF-AT-06 y
 * `lastAcceptedScanOf()` responde en que estado quedo la persona (RF-AT-12,
 * ADR-024). Las dos resuelven por `(employee_id, occurred_at DESC)` con un
 * `LIMIT 1`, asi que la tarea 3.5 anade **una fila mas leida** por escaneo, no
 * un recorrido del historico. Desde esa tarea las dos devuelven
 * {@see AcceptedScan} y no instantes sueltos: el anti-rebote necesita saber
 * **que fue** el escaneo vecino y la resolucion de la intencion **de que tramo**
 * venia.
 *
 * ## Lo que no se guarda
 *
 * El payload del QR no se almacena, solo su huella (RS-03): quien lea esta tabla
 * no puede fabricar una tarjeta valida. `client_meta` lleva version de la app y
 * modelo de tablet, jamas un nombre (regla dura 21).
 *
 * ## `employees.id`
 *
 * Se resuelve con una consulta directa a esa tabla por el mismo motivo y con el
 * mismo alcance que en {@see EloquentWorkDayRepository}: `scan_events.
 * employee_id` es una clave foranea a `employees`, y `EmployeeSnapshot` no lleva
 * la clave interna porque en la API nunca viaja.
 */
final readonly class EloquentScanLog implements ScanLog
{
    public function __construct(private ConnectionInterface $connection) {}

    public function record(ScanRecord $scan): bool
    {
        $written = ScanEvent::query()->insertOrIgnore([
            'scan_id' => $scan->scanId,
            'device_id' => $scan->deviceId,
            'employee_id' => $scan->employeeUuid === null ? null : $this->employeeIdOf($scan->employeeUuid),
            'occurred_at' => $this->toTimestamp($scan->occurredAt),
            'recorded_at' => $this->toTimestamp($scan->recordedAt),
            'origin' => $scan->origin->value,
            'intent' => $scan->intent->value,
            'result' => $scan->result->value,
            'shift_entry_id' => $scan->shiftEntryUuid === null ? null : $this->shiftEntryIdOf($scan->shiftEntryUuid),
            'payload_fingerprint' => $scan->payloadFingerprint,
            'client_meta' => json_encode($scan->clientMeta, JSON_THROW_ON_ERROR),
            'clock_skew_seconds' => $scan->clockSkewSeconds,
            'flagged_for_review' => $scan->flaggedForReview,
            'worked_minutes' => $scan->workedMinutes,
        ]);

        return $written > 0;
    }

    public function find(string $scanId): ?RecordedScan
    {
        /** @var object{scan_id: string, employee_uuid: string|null, occurred_at: string, recorded_at: string, result: string, work_date: string|null, worked_minutes: int|null}|null $row */
        $row = $this->connection->table('scan_events')
            ->leftJoin('employees', 'employees.id', '=', 'scan_events.employee_id')
            ->leftJoin('shift_entries', 'shift_entries.id', '=', 'scan_events.shift_entry_id')
            ->where('scan_events.scan_id', $scanId)
            ->select([
                'scan_events.scan_id',
                'employees.uuid as employee_uuid',
                'scan_events.occurred_at',
                'scan_events.recorded_at',
                'scan_events.result',
                'shift_entries.work_date',
                'scan_events.worked_minutes',
            ])
            ->first();

        if ($row === null) {
            return null;
        }

        return new RecordedScan(
            scanId: $row->scan_id,
            employeeUuid: $row->employee_uuid,
            occurredAt: $this->toUtc($row->occurred_at),
            recordedAt: $this->toUtc($row->recorded_at),
            result: ScanResult::from($row->result),
            // La columna es `DATE`; el controlador de PostgreSQL la devuelve
            // como `YYYY-MM-DD`, que es exactamente la forma que espera
            // `WorkDate::fromIsoDate()`.
            workDate: $row->work_date === null ? null : substr($row->work_date, 0, 10),
            workedMinutes: $row->worked_minutes,
        );
    }

    public function acceptedScansAdjacentTo(string $employeeUuid, DateTimeImmutable $instant): array
    {
        $employeeId = $this->employeeIdOf($employeeUuid);

        if ($employeeId === null) {
            return [];
        }

        $at = $this->toTimestamp($instant);

        // Dos consultas con `LIMIT 1` y no un `ORDER BY abs(...)`: asi las dos
        // caben en el indice `(employee_id, occurred_at DESC)` en lugar de
        // ordenar el historico entero del empleado en cada fichaje. Con cuatro
        // anos de retencion (RL-02) eso son miles de filas por persona.
        $before = $this->acceptedScansOf($employeeId)
            ->where('scan_events.occurred_at', '<=', $at)
            ->orderByDesc('scan_events.occurred_at')
            ->first();

        // Estrictamente posterior: sin el `>`, un escaneo con el mismo
        // `occurred_at` exacto aparecerian dos veces y la ventana se medira
        // igual, pero la consulta haria trabajo de mas.
        $after = $this->acceptedScansOf($employeeId)
            ->where('scan_events.occurred_at', '>', $at)
            ->orderBy('scan_events.occurred_at')
            ->first();

        $adjacent = [];

        foreach ([$before, $after] as $candidate) {
            $scan = $this->acceptedScanOf($candidate);

            if ($scan instanceof AcceptedScan) {
                $adjacent[] = $scan;
            }
        }

        return $adjacent;
    }

    public function lastAcceptedScanOf(string $employeeUuid): ?AcceptedScan
    {
        $employeeId = $this->employeeIdOf($employeeUuid);

        if ($employeeId === null) {
            return null;
        }

        // Sin cota inferior y por el mismo indice `(employee_id, occurred_at
        // DESC)` que la ventana de RF-AT-06: lo que se busca es el ESTADO en que
        // quedo la persona, y ese estado puede ser de hace media hora —lo que
        // dura una comida— o de hace cuatro. `ORDER BY occurred_at DESC LIMIT 1`
        // lee una sola fila del indice, no el historico.
        return $this->acceptedScanOf(
            $this->acceptedScansOf($employeeId)->orderByDesc('scan_events.occurred_at')->first()
        );
    }

    /**
     * Los escaneos del empleado que **produjeron tramo**, con el `uuid` publico
     * de ese tramo.
     *
     * El `LEFT JOIN` y no un `JOIN`: `scan_events.shift_entry_id` puede ser nulo
     * en una fila importada, y un `JOIN` la haria desaparecer de la ventana
     * anti-rebote sin que nadie se enterara. {@see AcceptedScan} admite el nulo
     * y sabe que hacer con el.
     */
    private function acceptedScansOf(int $employeeId): Builder
    {
        return $this->connection->table('scan_events')
            ->leftJoin('shift_entries', 'shift_entries.id', '=', 'scan_events.shift_entry_id')
            ->where('scan_events.employee_id', $employeeId)
            ->whereIn('scan_events.result', ScanResult::acceptedValues())
            ->select([
                'scan_events.occurred_at',
                'scan_events.result',
                'shift_entries.uuid as shift_entry_uuid',
            ]);
    }

    /**
     * Traduce la fila al vocabulario del dominio, o `null` si no hay fila.
     *
     * `ScanResult::action()` devuelve `null` para los cuatro rechazos, que aqui
     * no pueden llegar —la consulta ya los excluye— pero el tipado lo
     * contempla: antes que construir un `AcceptedScan` imposible, se descarta.
     */
    private function acceptedScanOf(mixed $row): ?AcceptedScan
    {
        if (! $row instanceof stdClass) {
            return null;
        }

        /** @var mixed $result */
        $result = $row->result ?? null;
        /** @var mixed $occurredAt */
        $occurredAt = $row->occurred_at ?? null;
        /** @var mixed $shiftEntryUuid */
        $shiftEntryUuid = $row->shift_entry_uuid ?? null;

        if (! is_string($result) || ! (is_string($occurredAt) || $occurredAt instanceof DateTimeInterface)) {
            return null;
        }

        $action = ScanResult::from($result)->action();

        if (! $action instanceof ClockingAction) {
            return null;
        }

        return new AcceptedScan(
            occurredAt: $this->toUtc($occurredAt),
            action: $action,
            shiftEntryUuid: is_string($shiftEntryUuid) ? $shiftEntryUuid : null,
        );
    }

    private function employeeIdOf(string $employeeUuid): ?int
    {
        $id = $this->connection->table('employees')->where('uuid', $employeeUuid)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function shiftEntryIdOf(string $uuid): ?int
    {
        $id = $this->connection->table('shift_entries')->where('uuid', $uuid)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function toTimestamp(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s.uP');
    }

    private function toUtc(string|DateTimeInterface $value): DateTimeImmutable
    {
        $instant = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable($value);

        // Regla dura 3: hacia arriba solo salen instantes en UTC. La columna es
        // `TIMESTAMPTZ` y PostgreSQL la devuelve en la zona de la sesion, que no
        // tiene por que ser la misma manana.
        return $instant->setTimezone(new DateTimeZone('UTC'));
    }
}
