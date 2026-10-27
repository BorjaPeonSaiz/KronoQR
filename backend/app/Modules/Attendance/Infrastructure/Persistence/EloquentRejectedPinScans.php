<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use App\Modules\Attendance\Application\Port\RejectedPinScans;
use App\Modules\Attendance\Application\Port\ScanResult;
use App\Modules\Attendance\Domain\Policy\PinAttemptRecoveryPolicy;
use App\Modules\Attendance\Domain\ValueObject\ClockingAction;
use App\Modules\Attendance\Domain\ValueObject\RejectedPinAttempt;
use App\Modules\Attendance\Domain\ValueObject\ScanRejectionReason;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Los fichajes por PIN rechazados con dueño (RN-19, ADR-043), leidos hacia
 * atras de `scan_events`.
 *
 * **No decide nada**: filtra por la columna que el fichaje ya escribio
 * —`claimed_employee_id`— y devuelve los fichajes que podrian subsanar cada
 * intento. Que fichajes subsanan y si lo hacen lo decide el dominio
 * ({@see PinAttemptRecoveryPolicy}).
 *
 * ## Indices
 *
 * - `rejectedBetween()` entra por `scan_events_pin_claims_recorded_at_index`,
 *   **parcial** sobre `recorded_at WHERE claimed_employee_id IS NOT NULL`: una
 *   minoria diminuta del historico. Sin el, la pasada nocturna recorreria la
 *   tabla entera por un rango de `recorded_at`.
 * - `rejectedOf()` cae en el mismo indice parcial: `claimed_employee_id = ?`
 *   implica su condicion. Se pide solo para las jornadas que la pasada ya ve
 *   subsanadas.
 * - `recoveringScansOf()` usa el indice existente
 *   `scan_events_employee_id_occurred_at_index`: una persona y diez minutos.
 *
 * Se une con `employees` para convertir `claimed_employee_id` en el
 * identificador publico y al reves, igual que {@see EloquentOutOfOrderScans}.
 * Ni un nombre ni un codigo de empleado salen de aqui (regla dura 21).
 */
final readonly class EloquentRejectedPinScans implements RejectedPinScans
{
    public function __construct(private ConnectionInterface $connection) {}

    public function rejectedBetween(DateTimeImmutable $fromRecordedAt, DateTimeImmutable $toRecordedAt): array
    {
        return $this->attempts(
            $this->claims()->whereBetween('scan_events.recorded_at', [
                $this->toTimestamp($fromRecordedAt),
                $this->toTimestamp($toRecordedAt),
            ]),
        );
    }

    public function rejectedOf(string $claimantUuid, DateTimeImmutable $fromOccurredAt, DateTimeImmutable $toOccurredAt): array
    {
        return $this->attempts(
            $this->claims()
                ->where('employees.uuid', $claimantUuid)
                ->where('scan_events.occurred_at', '>=', $this->toTimestamp($fromOccurredAt))
                ->where('scan_events.occurred_at', '<', $this->toTimestamp($toOccurredAt)),
        );
    }

    public function recoveringScansOf(string $employeeUuid, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<string|DateTimeInterface> $instants */
        $instants = $this->connection->table('scan_events')
            ->join('employees', 'employees.id', '=', 'scan_events.employee_id')
            ->where('employees.uuid', $employeeUuid)
            ->whereIn('scan_events.result', self::recoveringResults())
            ->whereBetween('scan_events.occurred_at', [$this->toTimestamp($from), $this->toTimestamp($to)])
            ->orderBy('scan_events.occurred_at')
            ->pluck('scan_events.occurred_at')
            ->all();

        return array_map(fn (string|DateTimeInterface $at): DateTimeImmutable => $this->toUtc($at), $instants);
    }

    /**
     * Los valores de `scan_events.result` que subsanan un PIN rechazado.
     *
     * **Los decide el dominio** ({@see PinAttemptRecoveryPolicy::recovers()},
     * R3-AR-04) y aqui solo se traducen a la columna: cada accion y cada motivo
     * de rechazo se pregunta uno a uno, asi que un desenlace nuevo entra o queda
     * fuera por lo que diga la regla, no por lo que recuerde este adaptador.
     *
     * @return list<string>
     */
    private static function recoveringResults(): array
    {
        $results = [];

        foreach (ClockingAction::cases() as $action) {
            if (PinAttemptRecoveryPolicy::recovers($action)) {
                $results[] = ScanResult::forAction($action)->value;
            }
        }

        foreach (ScanRejectionReason::cases() as $reason) {
            if (PinAttemptRecoveryPolicy::recovers($reason)) {
                $results[] = ScanResult::fromRejection($reason)->value;
            }
        }

        return $results;
    }

    /** Las filas con claim unidas a su dueño, con la condicion del indice parcial literal. */
    private function claims(): Builder
    {
        return $this->connection->table('scan_events')
            ->join('employees', 'employees.id', '=', 'scan_events.claimed_employee_id')
            ->whereNotNull('scan_events.claimed_employee_id');
    }

    /** @return list<RejectedPinAttempt> */
    private function attempts(Builder $query): array
    {
        /** @var list<object{scan_id: string, claimant_uuid: string, occurred_at: string, recorded_at: string, pin_lockout: bool|int|string}> $rows */
        $rows = $query
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
