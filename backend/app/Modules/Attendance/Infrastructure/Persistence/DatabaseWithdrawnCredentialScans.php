<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use App\Modules\Attendance\Application\Port\ScanResult;
use App\Modules\Attendance\Application\Port\WithdrawnCredentialScans;
use App\Modules\Attendance\Domain\ValueObject\WithdrawnCredentialScan;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/**
 * Los escaneos de tarjeta autentica usada antes de su retirada (RN-20,
 * ADR-047), leidos hacia atras de `scan_events`.
 *
 * **No decide nada**: el fichaje ya escribio la decision —un rechazo de
 * credencial (`rejected_revoked` o `rejected_unknown`) **con** `employee_id` y
 * marcado para revision— y aqui solo se lee. Las tres condiciones juntas solo
 * las cumple RN-20: un rechazo de credencial sin titular no lleva `employee_id`,
 * y con titular no se marca si fue posterior a la retirada. **Desde la 2.3.0
 * tambien el PIN** (`pin_kiosk`, enmienda del 29-10-2026 de ADR-043): el PIN
 * correcto de una persona de baja con fecha dentro de su periodo se escribe
 * igual, `rejected_unknown` con `employee_id` y marca. Ningun otro rechazo de PIN
 * lleva `employee_id` y marca a la vez: el fichaje por PIN aceptado si se marca
 * (RF-AT-11), pero no es un rechazo. La revision agrupa por persona y jornada,
 * asi que un dia con tarjeta y con PIN abre una sola incidencia.
 *
 * Entra por `scan_events_flagged_for_review_index`, parcial sobre las filas
 * marcadas y ordenado por `recorded_at`: es exactamente el rango que se pide.
 * Se une con `employees` para el UUID y para saber si la persona esta de baja
 * (`withdrawal`). Ni un nombre ni el motivo de la revocacion salen de aqui.
 */
final readonly class DatabaseWithdrawnCredentialScans implements WithdrawnCredentialScans
{
    private const string QR_KIOSK = 'qr_kiosk';

    private const string PIN_KIOSK = 'pin_kiosk';

    private const string TERMINATED = 'terminated';

    public function __construct(private ConnectionInterface $connection) {}

    public function withdrawnBetween(DateTimeImmutable $fromRecordedAt, DateTimeImmutable $toRecordedAt): array
    {
        /** @var list<object{scan_id: string, holder_uuid: string, holder_status: string, occurred_at: string, recorded_at: string}> $rows */
        $rows = $this->connection->table('scan_events')
            ->join('employees', 'employees.id', '=', 'scan_events.employee_id')
            ->where('scan_events.flagged_for_review', true)
            ->whereIn('scan_events.origin', [self::QR_KIOSK, self::PIN_KIOSK])
            ->whereIn('scan_events.result', [ScanResult::REJECTED_REVOKED->value, ScanResult::REJECTED_UNKNOWN->value])
            ->whereBetween('scan_events.recorded_at', [
                $this->toTimestamp($fromRecordedAt),
                $this->toTimestamp($toRecordedAt),
            ])
            ->orderBy('scan_events.occurred_at')
            ->orderBy('scan_events.scan_id')
            ->select([
                'scan_events.scan_id',
                'employees.uuid as holder_uuid',
                'employees.status as holder_status',
                'scan_events.occurred_at',
                'scan_events.recorded_at',
            ])
            ->get()
            ->all();

        $scans = [];

        foreach ($rows as $row) {
            $scans[] = new WithdrawnCredentialScan(
                scanId: $row->scan_id,
                holderUuid: $row->holder_uuid,
                occurredAt: $this->toUtc($row->occurred_at),
                recordedAt: $this->toUtc($row->recorded_at),
                holderOffboarded: $row->holder_status === self::TERMINATED,
            );
        }

        return $scans;
    }

    private function toUtc(string|DateTimeInterface $value): DateTimeImmutable
    {
        $instant = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable($value);

        return $instant->setTimezone(new DateTimeZone('UTC'));
    }

    private function toTimestamp(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s.uP');
    }
}
