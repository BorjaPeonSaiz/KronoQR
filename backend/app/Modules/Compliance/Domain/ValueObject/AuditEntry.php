<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use InvalidArgumentException;
use LogicException;

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
 *
 * **La forma de los hashes se exige al escribir, no al leer.** Un asiento nuevo
 * solo nace de {@see AuditChain::link()}, que rechaza un eslabon anterior mal
 * formado y calcula el suyo. Una fila leida de la tabla se acepta tal como
 * esta: si alguien quito el `CHECK` y dejo un hash truncado, eso es una
 * manipulacion que el verificador tiene que **denunciar en esa fila** y seguir
 * con las demas, no una excepcion que corta la verificacion y la retencion.
 */
final readonly class AuditEntry
{
    /** SHA-256 en hexadecimal minusculo: el mismo `CHECK` de `audit_log.hash`. */
    private const string HASH = '/\A[0-9a-f]{64}\z/';

    /**
     * @param  string  $previousHash  El eslabon anterior, o `''` si la fila lo trae a `NULL`: el
     *                                verificador lo denuncia como eslabon roto, no se inventa.
     * @param  string  $hash  El de la fila tal como esta: si no tiene la forma de un SHA-256, el
     *                        verificador lo denuncia como contenido alterado.
     */
    public function __construct(
        public AuditEntryDraft $draft,
        public string $previousHash,
        public string $hash,
        public ?int $id = null,
    ) {
        if ($id !== null && $id <= 0) {
            throw new InvalidArgumentException('El identificador de un asiento de auditoria es positivo, y llego '.$id.'.');
        }
    }

    /**
     * Si `$hash` tiene la forma de `audit_log.hash`: 64 cifras hexadecimales en
     * minuscula. Un hash truncado o en mayusculas nunca casaria con el
     * recalculado y, al escribir, seria una fila que el `CHECK` de la tabla
     * rechaza a destiempo (H5).
     */
    public static function isWellFormedHash(string $hash): bool
    {
        return preg_match(self::HASH, $hash) === 1;
    }

    /**
     * El identificador de la fila, para señalarla en una rotura de la cadena.
     * Una entrada leida de la tabla siempre lo trae: sin el, el hallazgo
     * apuntaria a la fila 0 en lugar de fallar (R1-AR-03).
     *
     * @throws LogicException si la entrada todavia no se ha escrito
     */
    public function persistedId(): int
    {
        return $this->id ?? throw new LogicException('La entrada de auditoria todavia no se ha escrito: no tiene identificador.');
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
