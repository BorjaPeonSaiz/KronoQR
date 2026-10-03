<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Application\Command\DiscardedScanReportInput;
use App\Modules\Attendance\Application\Command\ReportDiscardedScansCommand;
use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\DiscardedScanReportLog;
use App\Modules\Attendance\Application\Port\DiscardedScanReportRecord;
use App\Modules\Attendance\Application\Port\EmployeeDirectory;
use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Attendance\Domain\Policy\DiscardedScanAttributionPolicy;
use App\Modules\Attendance\Domain\ValueObject\DiscardedScanAttribution;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Support\ConstantTimeFloor;
use DateTimeImmutable;

/**
 * **Guardar los avisos de fichajes que el quiosco descarto** (RN-22, ADR-047).
 *
 * El quiosco saco esos fichajes de su cola porque el servidor declaro invalida
 * la peticion, y los conserva aparte hasta que este caso de uso acusa su
 * `scan_id`. **El aviso no registra el fichaje**: el servidor acaba de decir que
 * esa peticion no vale, y registrarla por esta puerta seria escribir en el
 * registro legal algo que nadie ha validado. Lo que hace es guardar el aviso y
 * su atribucion; la incidencia `discarded_scan` la abre la revision diaria.
 *
 * ## Atribuir sin abrir un oraculo (RS-03, F5 del dictamen del bloque 18)
 *
 * Por tarjeta, con el mismo resolutor que `POST /api/v1/scan`; por PIN, con una
 * sola busqueda por codigo de forma fija ({@see DiscardedScanAttributionPolicy}).
 * La respuesta no dice si se atribuyo, asi que lo unico que podria hablar es el
 * tiempo: **cada aviso se rellena hasta el suelo de tiempo constante pase lo que
 * pase** —tarjeta vigente, retirada o falsa; codigo existente, inexistente o de
 * baja; `scan_id` ya avisado o ya registrado—. El suelo del resolutor solo cubre
 * sus rechazos; este cubre el aviso entero.
 *
 * ## Idempotente por `scan_id` (regla dura 8)
 *
 * Lo decide el UNIQUE de `discarded_scan_reports`, sin `SELECT` previo. El acuse
 * lleva **todos** los `scan_id` recibidos, tambien los ya conocidos: son los que
 * el quiosco puede olvidar.
 *
 * ## Por que no hay una transaccion que envuelva los diez
 *
 * Cada aviso es una sola sentencia, atomica e idempotente por si misma, y no
 * escribe nada mas. Una transaccion alrededor de diez suelos de tiempo
 * mantendria abierta una conexion un cuarto de segundo sin proteger nada: si un
 * aviso falla, los anteriores ya estan guardados y el quiosco reenvia el lote
 * entero, que los acusa sin duplicarlos.
 *
 * **No se guarda ni el payload ni el codigo** (regla dura 21): salen de aqui con
 * la peticion.
 */
final readonly class ReportDiscardedScans
{
    public function __construct(
        private DiscardedScanReportLog $reports,
        private CredentialResolver $credentials,
        private EmployeeDirectory $employees,
        private ScanMetrics $metrics,
        private Clock $clock,
        private ConstantTimeFloor $floor,
    ) {}

    /**
     * @return list<string> Los `scan_id` acusados, en el orden en que llegaron.
     */
    public function handle(ReportDiscardedScansCommand $command): array
    {
        // Una sola recepcion para todo el envio: es cuando llegaron los avisos.
        $recordedAt = $this->clock->now();
        $policy = new DiscardedScanAttributionPolicy;

        $acknowledged = [];

        foreach ($command->reports as $report) {
            $startedAt = $this->floor->startedAt();

            $attribution = $this->attribute($policy, $report, $recordedAt);

            $written = $this->reports->record(new DiscardedScanReportRecord(
                scanId: $report->scanId,
                deviceId: $command->deviceId,
                origin: $report->origin,
                occurredAt: $report->occurredAt,
                discardedAt: $report->discardedAt,
                recordedAt: $recordedAt,
                httpStatus: $report->httpStatus,
                problemType: $report->problemType,
                attribution: $attribution,
            ));

            // Al insertar, no al reenviar (F8): la serie cuenta avisos, no envios.
            if ($written) {
                $this->metrics->discardedScanReported($command->deviceUuid, $attribution->isAttributed());
            }

            $this->floor->padTo($startedAt);

            $acknowledged[] = $report->scanId;
        }

        return $acknowledged;
    }

    private function attribute(
        DiscardedScanAttributionPolicy $policy,
        DiscardedScanReportInput $report,
        DateTimeImmutable $recordedAt,
    ): DiscardedScanAttribution {
        if ($report->origin === ScanOrigin::QR_KIOSK) {
            return $policy->forCard(
                $this->credentials->resolve($report->qrPayload ?? ''),
                $report->occurredAt,
                $recordedAt,
            );
        }

        return $policy->forEmployeeCode($this->employees->findByCode($report->employeeCode ?? ''));
    }
}
