<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain;

use App\Modules\Compliance\Domain\ValueObject\AuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;

/**
 * La cadena de hash de la auditoria (doc 02 §7.4, RS-07, ADR-010).
 *
 * ```
 * hash_n = SHA256( prev_hash || occurred_at || actor || action || subject || canonical_json(payload) )
 * ```
 *
 * La entrada genesis usa `prev_hash = SHA256("FICHAJE-HOTEL-GENESIS")`.
 *
 * **Dominio puro.** Ni facades, ni framework, ni reloj: recibe el instante ya
 * resuelto dentro del borrador (regla dura 2) y la unica funcion externa que usa
 * es `hash()`, que es de PHP. Se puede ejecutar en una prueba unitaria sin base
 * de datos, que es lo que exige el §9.5 para el calculo.
 *
 * **La concatenacion, en detalle.** Cada uno de los seis componentes tiene una
 * forma canonica que **termina en el separador de registro `\x1e`**. Sin el, la
 * concatenacion tendria fronteras ambiguas: la accion `a` con sujeto `bc` y la
 * accion `ab` con sujeto `c` producirian la misma cadena y, por tanto, el mismo
 * hash. Es la misma formula del §7.4 —los mismos seis componentes, en el mismo
 * orden— con la serializacion de cada componente definida sin ambiguedad, que es
 * lo que el propio §7.4 exige al hablar de `canonical_json`.
 */
final class AuditChain
{
    /**
     * Semilla de la genesis. Literal del doc 02 §7.4: **no se cambia**. Cambiarla
     * invalida la verificacion de todo lo escrito antes.
     */
    public const string GENESIS_SEED = 'FICHAJE-HOTEL-GENESIS';

    private const string ALGORITHM = 'sha256';

    /**
     * `prev_hash` de la primera entrada de la instalacion.
     *
     * El valor es `SHA256("FICHAJE-HOTEL-GENESIS")`, es decir
     * `5a4bce58…5f2e`, y esta fijado por prueba unitaria con vector literal: si
     * alguien cambia el algoritmo o la semilla, esa prueba falla antes de que
     * el cambio llegue a tocar una fila.
     */
    public static function genesisHash(): string
    {
        return hash(self::ALGORITHM, self::GENESIS_SEED);
    }

    /**
     * Encadena un borrador detras de `$previousHash` y devuelve la entrada ya
     * sellada.
     *
     * `$previousHash` es el `hash` de la ultima entrada existente, o
     * `genesisHash()` si no hay ninguna. **Quien lo averigua es el adaptador**,
     * dentro de la transaccion y con el candado que serializa los apuntes: el
     * dominio no consulta la tabla.
     */
    public static function link(AuditEntryDraft $draft, string $previousHash): AuditEntry
    {
        return new AuditEntry($draft, $previousHash, self::hashFor($draft, $previousHash));
    }

    /**
     * El calculo, aislado, para que el verificador pueda recomputar el hash de
     * una fila leida de la base de datos y compararlo con el almacenado.
     */
    public static function hashFor(AuditEntryDraft $draft, string $previousHash): string
    {
        return hash(self::ALGORITHM, implode('', [
            $previousHash."\x1e",
            $draft->canonicalOccurredAt(),
            $draft->actor->canonical(),
            $draft->action->value."\x1e",
            $draft->subject->canonical(),
            $draft->payload->canonical(),
        ]));
    }

    /**
     * Comparacion en tiempo constante de dos hashes.
     *
     * Aqui no hay secreto que proteger —los dos valores estan en la tabla—, pero
     * el habito es el de `/revision-cumplimiento` bloque C y cuesta lo mismo.
     */
    public static function matches(string $expected, string $actual): bool
    {
        return hash_equals($expected, $actual);
    }
}
