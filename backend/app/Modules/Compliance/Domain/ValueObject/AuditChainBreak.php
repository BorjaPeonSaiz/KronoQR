<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Una rotura concreta de la cadena, con lo justo para investigarla: que fila,
 * de que tipo y que se esperaba frente a que hay.
 *
 * **Sin datos personales.** El hallazgo se publica en el log tecnico y en la
 * alerta, y de ahi puede acabar en un paquete de diagnostico: lleva
 * identificadores y hashes, nunca el payload ni el actor resuelto a un nombre
 * (regla dura 21).
 */
final readonly class AuditChainBreak
{
    public function __construct(
        public int $entryId,
        public AuditChainBreakKind $kind,
        public string $expectedHash,
        public string $actualHash,
    ) {}

    /**
     * Linea para el log y para la salida del comando. Deliberadamente escueta:
     * lo que hay que hacer al verla esta en el runbook, no en el mensaje.
     */
    public function describe(): string
    {
        return sprintf(
            'audit_log #%d · %s · esperado %s · encontrado %s',
            $this->entryId,
            $this->kind->value,
            $this->expectedHash,
            $this->actualHash,
        );
    }
}
