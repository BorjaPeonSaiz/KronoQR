<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use App\Modules\Attendance\Application\Port\RejectedPinScans;
use App\Modules\Attendance\Application\Port\ScanResult;
use App\Modules\Attendance\Domain\Policy\PinAttemptRecoveryPolicy;
use App\Modules\Attendance\Domain\ValueObject\RejectedPinAttempt;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/**
 * Los fichajes por PIN rechazados con dueño (RN-19, ADR-043), leidos hacia
 * atras de `scan_events`.
 *
 * **No decide nada**: filtra por la columna que el fichaje ya escribio
 * —`claimed_employee_id`— y devuelve los fichajes que podrian subsanar cada
 * intento. Si lo subsanan lo decide el dominio
 * ({@see PinAttemptRecoveryPolicy}).
 *
 * ## Indices
 *
 * - `rejectedBetween()` entra por `scan_events_pin_claims_recorded_at_index`,
 *   **parcial** sobre `recorded_at WHERE claimed_employee_id IS NOT NULL`: una
 *   minoria diminuta del historico. Sin el, la pasada nocturna recorreria la
 *   tabla entera por un rango de `recorded_at`.
 * - `recoveringScansOf()` usa el indice existente
 *   `scan_events_employee_id_occurred_at_index`: una persona y diez minutos.
 *
 * Se une con `employees` para convertir `claimed_employee_id` en el
 * identificador publico y al reves, igual que {@see EloquentOutOfOrderScans}.
 * Ni un nombre ni un codigo de empleado salen de aqui (regla dura 21).
 */
final readonly class EloquentRejectedPinScans implements RejectedPinScans
{
    /**
     * Los resultados que subsanan un PIN rechazado (doc 01 §4, RN-19): los
     * cuatro aceptados, el anti-rebote —la persona ficho y el servidor lo
     * reconocio como repetido— y el irreconciliable de RN-18 —la persona ficho
     * y su propia incidencia ya lo lleva a revision—.
     */
    private const array RECOVERING_RESULTS = [
        ScanResult::CLOCK_IN,
        ScanResult::CLOCK_OUT,
        ScanResult::BREAK_START,
        ScanResult::BREAK_END,
        ScanResult::REJECTED_DEBOUNCE,
        ScanResult::REJECTED_OUT_OF_ORDER,
    ];

    public function __construct(private ConnectionInterface $connection) {}

    public function rejectedBetween(DateTimeImmutable $fromRecordedAt, DateTimeImmutable $toRecordedAt): array
    {
        /** @var list<object{scan_id: string, claimant_uuid: string, occurred_at: string, recorded_at: string, pin_lockout: bool|int|string}> $rows */
        $rows = $this->connection->table('scan_events')
            ->join('employees', 'employees.id', '=', 'scan_events.claimed_employee_id')
            // La condicion del indice parcial, literal: es la que reduce el
            // historico a las filas con claim.
            ->whereNotNull('scan_events.claimed_employee_id')
            ->whereBetween('scan_events.recorded_at', [
                $this->toTimestamp($fromRecordedAt),
                $this->toTimestamp($toRecordedAt),
            ])
            // El primero de cada jornada viaja al contexto: «primero» medido en
            // el momento real, con `scan_id` para desempatar de forma estable.
            ->orderBy('scan_events.occurred_at')
            ->orderBy('scan_events.scan_id')
            ->select([
                'scan_events.scan_id',
                'employees.uuid as claimant_uuid',
                'scan_events.occurred_at',
                'scan_events.recorded_at',
                'scan_events.pin_lockout',
            ])
            ->get()
            ->all();

        $attempts = [];

        foreach ($rows as $row) {
            $attempts[] = new RejectedPinAttempt(
                scanId: $row->scan_id,
                claimantUuid: $row->claimant_uuid,
                occurredAt: $this->toUtc($row->occurred_at),
                recordedAt: $this->toUtc($row->recorded_at),
                lockout: filter_var($row->pin_lockout, FILTER_VALIDATE_BOOL),
            );
        }

        return $attempts;
    }

    public function recoveringScansOf(string $employeeUuid, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<string|DateTimeInterface> $instants */
        $instants = $this->connection->table('scan_events')
            ->join('employees', 'employees.id', '=', 'scan_events.employee_id')
            ->where('employees.uuid', $employeeUuid)
            ->whereIn('scan_events.result', array_map(
                static fn (ScanResult $result): string => $result->value,
                self::RECOVERING_RESULTS,
            ))
            ->whereBetween('scan_events.occurred_at', [$this->toTimestamp($from), $this->toTimestamp($to)])
            ->orderBy('scan_events.occurred_at')
            ->pluck('scan_events.occurred_at')
            ->all();

        return array_map(fn (string|DateTimeInterface $at): DateTimeImmutable => $this->toUtc($at), $instants);
    }

    /** La misma conversion que {@see EloquentScanLog}: hacia arriba solo sale UTC (regla dura 3). */
    private function toUtc(string|DateTimeInterface $value): DateTimeImmutable
    {
        $instant = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable($value);

        return $instant->setTimezone(new DateTimeZone('UTC'));
    }

    /** El formato que entiende `TIMESTAMPTZ`, igual que en {@see EloquentScanLog}. */
    private function toTimestamp(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s.uP');
    }
}
