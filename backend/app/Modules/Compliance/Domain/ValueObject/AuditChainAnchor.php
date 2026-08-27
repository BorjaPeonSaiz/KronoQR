<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use DateTimeImmutable;

/**
 * El sello de una particion de `audit_log` antes de soltarla (ADR-027).
 *
 * Es lo que convierte una purga de retencion en algo distinto de una
 * manipulacion. Cuando la particion de 2026 se suelta a los cuatro años, la
 * primera fila superviviente apunta con su `prev_hash` a una fila que ya no
 * existe. Sin ancla, el verificador denunciaria rotura **todos los dias**; con
 * ancla, encuentra ese `prev_hash` como `last_hash` de un sello y sigue.
 *
 * *«Faltan filas» frente a «faltan filas que alguien registro que iba a quitar,
 * y encajan».* Esa es toda la diferencia, y es la que hace que la alerta de
 * RS-07 siga significando algo.
 *
 * `sealed_by` es el rol que sello, no una persona: la purga la ejecuta el rol de
 * mantenimiento, que no aparece en el `.env` de la aplicacion.
 */
final readonly class AuditChainAnchor
{
    public function __construct(
        public int $partitionYear,
        public string $firstHash,
        public string $lastHash,
        public int $rowCount,
        public DateTimeImmutable $sealedAt,
        public string $sealedBy,
    ) {}
}
