<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Una entrada de auditoria ya encadenada: el hecho, el eslabon anterior y el
 * suyo propio.
 *
 * Inmutable por construccion, y **no tiene metodos que cambien nada**: la tabla
 * es solo-append (regla dura 6, ADR-010) y un objeto de dominio con un
 * `withPayload()` seria una invitacion a intentar lo que la base de datos ya
 * prohibe. Corregir una entrada de auditoria no existe como operacion; lo que
 * existe es escribir otra.
 *
 * `id` llega solo cuando la fila ya esta escrita: lo asigna la secuencia de
 * PostgreSQL, no el dominio.
 */
final readonly class AuditEntry
{
    public function __construct(
        public AuditEntryDraft $draft,
        public string $previousHash,
        public string $hash,
        public ?int $id = null,
    ) {}

    /**
     * La misma entrada con el identificador que le ha dado la base de datos.
     * No muta: devuelve otra instancia.
     */
    public function withId(int $id): self
    {
        return new self($this->draft, $this->previousHash, $this->hash, $id);
    }
}
