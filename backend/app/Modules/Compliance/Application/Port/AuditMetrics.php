<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\AuditChainVerification;
use App\Modules\Compliance\Domain\ValueObject\AuditPartitionStatus;
use DateTimeImmutable;

/**
 * Publicacion de las metricas de la auditoria, con
 * `audit_chain_verification_failures_total` a la cabeza (doc 02 §8.2).
 *
 * **Es una metrica que debe permanecer siempre en cero.** El §8.2 lo dice sin
 * matices: «cualquier incremento es un incidente de integridad, no una metrica
 * de tendencia». Por eso la alerta no tiene umbral —«cualquiera», doc 01 §9.3—
 * y por eso el verificador la publica **tambien cuando todo esta bien**: una
 * serie que desaparece es indistinguible de una que nunca fallo, y la regla
 * `absent()` de `infra/observability/prometheus/rules/audit.yml` es la que
 * descubre que el comando programado dejo de ejecutarse.
 *
 * Es un puerto y no una llamada directa por la razon de siempre: el caso de uso
 * no sabe si detras hay un fichero para el colector *textfile*, un registro de
 * `promphp` o nada. Hoy es lo primero —`/metrics` lo expone la tarea 3.1—; en
 * las pruebas es un doble que cuenta.
 */
interface AuditMetrics
{
    public function recordVerification(AuditChainVerification $result, DateTimeImmutable $at): void;

    public function recordPartitionStatus(AuditPartitionStatus $status, DateTimeImmutable $at): void;
}
