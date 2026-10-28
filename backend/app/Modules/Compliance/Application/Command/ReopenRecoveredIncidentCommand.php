<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Command;

use DateTimeImmutable;

/**
 * La orden de reabrir la incidencia que el sistema cerro, porque su jornada
 * vuelve a tener un intento sin subsanar (RN-19, enmienda del 27-10-2026 de
 * ADR-057 §2).
 *
 * Habla en escalares e identificadores publicos, como
 * {@see CloseRecoveredIncidentCommand}: este modulo no puede importar el dominio
 * de `Attendance` (doc 02 §1.6).
 */
final readonly class ReopenRecoveredIncidentCommand
{
    public function __construct(
        /** Valor de `incidents.type` del hallazgo. */
        public string $type,
        public string $employeeUuid,
        /** La jornada en `Y-m-d`, ya resuelta en la zona del centro (RN-05). */
        public string $workDate,
        /** El instante de la pasada que vio la recaida, en UTC. */
        public DateTimeImmutable $reopenedAt,
        /**
         * El `context` del hallazgo nuevo. Escalares, nunca datos personales.
         *
         * @var array<string, int|string>
         */
        public array $context,
        /**
         * `scan_id` de los intentos de la jornada: UUID v7 de la tablet, sin PII.
         *
         * @var list<string>
         */
        public array $attemptScanIds,
        /**
         * `scan_id` de los fichajes que subsanan los intentos subsanados.
         *
         * @var list<string>
         */
        public array $recoveringScanIds,
    ) {}
}
