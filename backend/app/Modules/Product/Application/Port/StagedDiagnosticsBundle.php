<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

/**
 * Un paquete ya escrito entero en un temporal, junto a su destino, a falta del
 * renombrado (`DiagnosticsBundleWriter::stage()`).
 *
 * Existe para poder guardar el pendiente de su asiento **entre** la escritura
 * y la publicacion: asi no puede quedar un asiento de un paquete que no llego a
 * escribirse (ADR-020, enmienda del 14-10-2026). Lleva la huella y si el
 * asiento esta pendiente para que `publish()` pueda exigir el comprobante del
 * paquete correcto.
 */
final readonly class StagedDiagnosticsBundle
{
    public function __construct(
        public string $temporary,
        public string $target,
        public string $sha256,
        public bool $auditPending,
    ) {}
}
