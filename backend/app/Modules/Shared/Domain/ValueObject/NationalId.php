<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Un documento de identidad en su **forma canonica**, la unica con la que se
 * calcula `employees.national_id_hash` (RL-08).
 *
 * ## Por que vive en `Shared` y no en `Workforce`
 *
 * Nacio como `ImportedEmployee::normaliseNationalId()` para el alta masiva
 * (correccion de la revision de la 5.5) y desde entonces la llamaban tres sitios
 * de `Workforce`: el alta, la importacion y la busqueda del directorio. La
 * busqueda de la persona de la exportacion legal (RF-IN-05, V21-3-PA-1) es de
 * `Compliance`, que no puede importar de `Workforce` (doc 02 §1.6). Copiar la
 * funcion alli habria sido reabrir el fallo que esta clase existe para cerrar:
 * **dos normalizaciones que se separan**. Un hash no admite comparaciones
 * aproximadas, asi que `digest('12345678z')` y `digest('12345678Z')` son dos
 * personas para la base de datos; si el alta normalizara de una forma y la
 * busqueda de otra, el auditor no encontraria nunca a quien busca, y si lo
 * hicieran la importacion y el alta, la misma persona tendria dos fichas.
 *
 * ## Que es la forma canonica
 *
 * Mayusculas, sin espacios —tambien el no separable—, sin guiones y sin puntos.
 * `12.345.678-z`, `12345678 Z` y `12345678Z` son el mismo documento escrito por
 * tres personas distintas, y en un CSV exportado a mano aparecen los tres.
 *
 * ## Lo que NO hace
 *
 * No valida la letra de control ni el formato de un DNI o NIE: el producto se
 * vende fuera de España (ADR-017) y el documento puede ser un pasaporte. Declara
 * una longitud minima tras normalizar, la misma que el importador ya aplicaba,
 * para que quien la necesite no escriba otro `4` por su cuenta.
 *
 * **Nunca se serializa ni se escribe en un log** (RL-08, regla dura 21): por eso
 * no implementa `Stringable` ni `JsonSerializable`, y `value` solo lo lee el
 * adaptador que lo convierte en digest dentro de la propia sentencia.
 */
final readonly class NationalId
{
    /**
     * Longitud minima de un documento una vez normalizado. Por debajo, no es un
     * documento sino ruido: emparejaria a personas distintas por casualidad.
     */
    public const int MIN_LENGTH = 4;

    private function __construct(public string $value) {}

    /**
     * El documento normalizado, o `null` si no queda nada al normalizar.
     *
     * **No rechaza los cortos**: la longitud minima la aplica quien decide que
     * hacer con ellos —el importador lo señala en su linea, la busqueda responde
     * `422`—, con {@see self::isTooShort()}. Si la aplicara aqui, el alta
     * individual dejaria de guardar el digest de un documento que hasta hoy si
     * guardaba, y eso es una regla de negocio que no le toca a esta clase.
     */
    public static function fromInput(string $raw): ?self
    {
        $normalised = self::normalise($raw);

        return $normalised === null ? null : new self($normalised);
    }

    /** Si es mas corto que {@see self::MIN_LENGTH} una vez normalizado. */
    public function isTooShort(): bool
    {
        return mb_strlen($this->value) < self::MIN_LENGTH;
    }

    /**
     * La forma canonica, sin comprobar longitud: `null` si la cadena se queda en
     * nada. Es idempotente, asi que aplicarla a un valor ya normalizado no lo
     * cambia.
     *
     * La usa el importador, que distingue «sin documento» (`null`) de «documento
     * demasiado corto» para decirle a RRHH en que linea se equivoco.
     */
    public static function normalise(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $normalised = str_replace([' ', '-', '.', "\u{00A0}"], '', mb_strtoupper(trim($raw)));

        return $normalised === '' ? null : $normalised;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
