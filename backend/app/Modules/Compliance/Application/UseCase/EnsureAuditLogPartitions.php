<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Port\AuditLogPartitions;
use App\Modules\Compliance\Application\Port\AuditMetrics;
use App\Modules\Compliance\Domain\ValueObject\AuditPartitionStatus;
use App\Modules\Shared\Application\Port\Clock;

/**
 * La obligacion operativa que trae el particionado (ADR-027): que nunca falte la
 * particion donde va a caer la siguiente entrada.
 *
 * **Dos trabajos, uno preventivo y otro correctivo.**
 *
 * - **Preventivo:** a partir de noviembre crea la particion del año siguiente,
 *   con dos meses de margen sobre el 1 de enero. Noviembre y no diciembre para
 *   que el aviso de que algo fue mal llegue antes de las vacaciones de Navidad,
 *   que es cuando menos gente hay para atenderlo y mas turnos se hacen.
 * - **Correctivo:** si falta la del año en curso, la crea igual y **lo declara**.
 *   Es un incidente, no una rutina: hasta ese momento cualquier accion auditable
 *   estaba fallando, porque un `INSERT` sin particion de destino aborta y
 *   arrastra la transaccion de la accion que lo provoco.
 *
 * El año sale del puerto `Clock` (regla dura 2 y ADR-021), en UTC (regla dura 3):
 * el cambio de año de la particion es el de UTC, igual que el de `occurred_at`.
 */
final readonly class EnsureAuditLogPartitions
{
    /**
     * Mes a partir del cual se adelanta la particion del año siguiente. Es
     * calendario de operacion, no un umbral legal de los de la regla dura 14.
     */
    public const int LEAD_MONTH = 11;

    public function __construct(
        private AuditLogPartitions $partitions,
        private AuditMetrics $metrics,
        private Clock $clock,
    ) {}

    public function handle(): AuditPartitionStatus
    {
        $now = $this->clock->now();
        $currentYear = (int) $now->format('Y');
        $existing = $this->partitions->years();

        $created = [];

        $currentYearWasMissing = ! \in_array($currentYear, $existing, true);

        if ($currentYearWasMissing) {
            $this->partitions->create($currentYear);
            $created[] = $currentYear;
        }

        $nextYear = $currentYear + 1;
        $nextYearReady = \in_array($nextYear, $existing, true);

        if (! $nextYearReady && (int) $now->format('n') >= self::LEAD_MONTH) {
            $this->partitions->create($nextYear);
            $created[] = $nextYear;
            $nextYearReady = true;
        }

        $status = new AuditPartitionStatus(
            currentYear: $currentYear,
            createdYears: $created,
            currentYearWasMissing: $currentYearWasMissing,
            nextYearReady: $nextYearReady,
        );

        $this->metrics->recordPartitionStatus($status, $now);

        return $status;
    }
}
