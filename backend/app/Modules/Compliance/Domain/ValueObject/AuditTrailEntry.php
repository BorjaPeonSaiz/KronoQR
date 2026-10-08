<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Un asiento `shift_entry.*` de `audit_log` tal y como se leyo: su `id`, su
 * accion y su `payload` ya decodificado (ADR-057 §4).
 *
 * No es el `AuditEntry` de la cadena —ese lleva hash, actor y momento y sirve
 * para verificarla—: aqui solo hace falta lo que el asiento afirma del tramo.
 */
final readonly class AuditTrailEntry
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(
        public int $id,
        public string $action,
        public array $payload,
    ) {}

    /**
     * Si lo escribio una correccion (RF-PA-04) y no un fichaje. Solo las
     * correcciones llevan `reason_code`; las dos familias comparten
     * `shift_entry.created` y `shift_entry.closed`.
     */
    public function isCorrection(): bool
    {
        return \array_key_exists('reason_code', $this->payload);
    }
}
