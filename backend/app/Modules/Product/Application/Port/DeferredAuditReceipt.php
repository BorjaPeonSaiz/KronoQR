<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\PendingDiagnosticsAudit;

/**
 * Comprobante de que el pendiente del asiento de un paquete esta guardado
 * (ADR-020, enmienda del 14-10-2026; RL-04, RL-19).
 *
 * `DiagnosticsBundleWriter::publish()` no publica un paquete con el asiento
 * pendiente sin el comprobante **de ese paquete** (misma huella). Asi, que un
 * paquete no salga sin su pendiente no depende de que el llamante se acuerde
 * del orden: depende del tipo.
 *
 * ## Quien lo emite
 *
 * Solo `DeliverDiagnosticsBundle`, justo despues de que
 * `PendingDiagnosticsAudits::save()` vuelva sin lanzar. PHP no tiene visibilidad
 * de paquete, asi que la restriccion la pone `DiagnosticsDeliveryTest`
 * (Architecture): ningun otro fichero de `app/` puede llamar a
 * {@see self::forSaved()}. Se pide el pendiente guardado, y no una huella
 * suelta, para que emitirlo exija haber construido lo que se guarda.
 *
 * @internal Solo `DeliverDiagnosticsBundle`.
 */
final readonly class DeferredAuditReceipt
{
    private function __construct(public string $sha256) {}

    public static function forSaved(PendingDiagnosticsAudit $saved): self
    {
        return new self($saved->sha256);
    }
}
