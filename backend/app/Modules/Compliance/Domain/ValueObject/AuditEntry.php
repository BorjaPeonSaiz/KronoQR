<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use InvalidArgumentException;

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
    /** SHA-256 en hexadecimal minusculo: el mismo `CHECK` de `audit_log.hash`. */
    private const string HASH = '/\A[0-9a-f]{64}\z/';

    /**
     * @param  string  $previousHash  El eslabon anterior, o `''` si la fila lo trae a `NULL`: el
     *                                verificador lo denuncia como eslabon roto, no se inventa.
     */
    public function __construct(
        public AuditEntryDraft $draft,
        public string $previousHash,
        public string $hash,
        public ?int $id = null,
    ) {
        // Un hash truncado o en mayusculas nunca casaria con el recalculado y
        // pareceria una manipulacion; al escribir, seria una fila que el
        // `CHECK` de la tabla rechaza a destiempo (H5).
        if (preg_match(self::HASH, $hash) !== 1 || ($previousHash !== '' && preg_match(self::HASH, $previousHash) !== 1)) {
            throw new InvalidArgumentException('Los hashes de un asiento de auditoria son SHA-256 en hexadecimal minusculo.');
        }

        if ($id !== null && $id <= 0) {
            throw new InvalidArgumentException('El identificador de un asiento de auditoria es positivo, y llego '.$id.'.');
        }
    }

    /**
     * La misma entrada con el identificador que le ha dado la base de datos.
     * No muta: devuelve otra instancia.
     */
    public function withId(int $id): self
    {
        return new self($this->draft, $this->previousHash, $this->hash, $id);
    }
}
