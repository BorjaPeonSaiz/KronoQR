<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Estado de las particiones anuales de `audit_log` tras pasar la tarea
 * programada (ADR-027).
 *
 * `currentYearWasMissing` es el hallazgo grave y no `created`: que la particion
 * del año en curso no existiera significa que, hasta ese momento, **toda accion
 * auditable estaba fallando** —el `INSERT` sin particion de destino aborta y
 * arrastra la transaccion de la accion—. Crearla resuelve el presente y no
 * borra el hecho: hay que mirar que ocurrio antes.
 */
final readonly class AuditPartitionStatus
{
    /**
     * @param  list<int>  $createdYears
     */
    public function __construct(
        public int $currentYear,
        public array $createdYears,
        public bool $currentYearWasMissing,
        public bool $nextYearReady,
    ) {}

    public function isHealthy(): bool
    {
        return ! $this->currentYearWasMissing;
    }
}
