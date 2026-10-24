<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Attendance\Domain\Event\UnprocessableScanDiscarded;
use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Shared\Domain\ValueObject\UtcInstant;

/**
 * El asiento `scan.discarded` (RN-22, ADR-061 §6.3, regla dura 6).
 *
 * **Actor `system`**: el descarte lo decide el servidor, no el quiosco que
 * reenvio el fichaje ni una persona. Atribuirselo al dispositivo diria que la
 * tablet decidio algo, y solo reintento.
 *
 * **Sujeto, el quiosco**: el fichaje descartado no tiene tramo —no lo crea, por
 * definicion— y la persona puede no conocerse. El `scan_id` y, si se atribuyo,
 * el `employee_uuid` van en la carga.
 *
 * **Sincrono, en la transaccion del descarte** (ADR-027): si el asiento no se
 * puede escribir, el descarte no se confirma y el fichaje se queda en la cola,
 * que es fallar hacia conservar.
 *
 * **Sin la clase de la excepcion ni texto libre** (reglas duras 6 y 21): eso es
 * diagnostico y va al log tecnico.
 */
final readonly class RecordServerScanDiscard
{
    public function __construct(private RecordAuditEntry $audit) {}

    public function handle(UnprocessableScanDiscarded $event): void
    {
        $payload = [
            'device_uuid' => $event->deviceUuid,
            'scan_id' => $event->scanId,
            // `->value`: el vocabulario de la columna. `Compliance` no nombra los
            // enums de `Attendance` (solo alcanza `Attendance\Domain\Event`).
            'origin' => $event->origin->value,
            'occurred_at' => UtcInstant::of($event->scannedAt),
            'recorded_at' => UtcInstant::of($event->discardedAt),
            'first_failed_at' => UtcInstant::of($event->history->firstFailedAt),
            'attempts' => $event->history->attempts,
            'attribution' => $event->attribution->value,
        ];

        if ($event->employeeUuid !== null) {
            $payload['employee_uuid'] = $event->employeeUuid;
        }

        $this->audit->handle(new RecordAuditEntryCommand(
            actor: AuditActor::system(),
            action: AuditAction::ScanDiscarded,
            subject: AuditSubject::of('device', $event->deviceId),
            payload: AuditPayload::of($payload),
            occurredAt: $event->occurredAt(),
        ));
    }
}
