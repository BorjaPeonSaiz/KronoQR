<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * **La lista blanca por palabra** (ADR-048, pasos 4 y 5; RF-PD-15, RL-19,
 * reglas duras 16 y 21).
 *
 * Recibe un texto que {@see ErrorMessageSanitizer} ya ha pasado por sus
 * patrones —los identificadores tecnicos que se conservan ya estan apartados—
 * y deja en el solo tres clases de cosas: palabras de {@see ErrorVocabulary},
 * marcadores y cifras cortas. Todo lo demas se sustituye.
 *
 * ## Paso 4: las cifras
 *
 * Ninguna lista de palabras filtra un identificador numerico, asi que:
 *
 * - **Serie de 7 cifras o mas.** Grupos de cifras unidos por un unico
 *   separador (espacio, punto, guion o barra), con un `+` opcional delante,
 *   que sumen siete cifras o mas pasan enteros a `[n]`. Caen asi el telefono
 *   en cualquier formato, el NAF, el DNI sin letra y cualquier tarjeta, sin
 *   depender de su forma concreta.
 * - **Cuatro cifras seguidas.** Un grupo, o una secuencia alfanumerica, que
 *   contenga cuatro cifras seguidas pasa entero a `[n]`: el codigo de empleado
 *   heredado (`HTL2019X0042`), un puerto, un año.
 * - **Alfanumerico con cifras (H4).** Una secuencia de letras y cifras de cinco
 *   caracteres o mas, con al menos dos cifras y una letra, pasa a `[n]` salvo
 *   que este ENTERA en el vocabulario (`sha256`, `base64`, `utf8mb4`): asi caen
 *   `a1b2c3` o `x7k2m9`, que no tienen cuatro cifras seguidas.
 * - **Posiciones tecnicas (H3).** Detras de `line `, `línea `, `linea `,
 *   `.php:`, `.js:`, `.ts:`, `.vue:` (con su `:columna` si la lleva) y del `#`
 *   de un argumento, **un unico grupo de hasta seis cifras** se conserva: es el
 *   numero de linea que hace util una traza. Un grupo mas largo o seguido de
 *   otro grupo no es un numero de linea y sigue las reglas de arriba
 *   (`line 612 345 678` → `line [n]`).
 *
 * ## Paso 5: las palabras
 *
 * Cada secuencia de letras se compara ENTERA con el vocabulario y, si no esta,
 * partida por sus cambios de caja (`EmployeeCodeAlreadyTaken` →
 * Employee|Code|Already|Taken, `getUserMedia` → get|User|Media, `HTTPError` →
 * HTTP|Error). **Si una sola parte no esta, cae la secuencia entera**: `McDonald`
 * da `…`, no `Mc…`. Una letra latina suelta se conserva siempre (la `x` de una
 * variable, la `e` de una excepcion); una letra suelta de otra escritura no,
 * porque en chino una letra es una palabra entera.
 *
 * Varios `…` seguidos, separados solo por espacios, se funden en uno: que no se
 * sepa ni cuantas palabras tenia el nombre.
 *
 * ## Propiedades
 *
 * - **Idempotente**: aplicar dos veces da lo mismo que una. Lo necesita el
 *   colector del paquete, que vuelve a aplicarla al leer.
 * - **Falla cerrado**: si una expresion no se puede evaluar, devuelve la cadena
 *   vacia, nunca el texto sin filtrar.
 * - **No conserva ninguna secuencia de letras fuera del vocabulario**, salvo las
 *   de una sola letra latina.
 */
final readonly class ErrorTextAllowlist
{
    /** Lo que queda en lugar de una palabra que no esta en el vocabulario. */
    public const string UNKNOWN_WORD = '…';

    /** Lo que queda en lugar de una cifra que puede identificar a alguien. */
    public const string NUMBER = '[n]';

    /** Una serie que suma tantas cifras o mas es un identificador. */
    private const int SERIES_DIGITS = 7;

    /** Cifras de un numero de linea que todavia se conserva (H3). */
    private const int TECHNICAL_DIGITS = 6;

    /** H4: alfanumerico con al menos estas cifras… */
    private const int MIXED_DIGITS = 2;

    /** …y al menos esta longitud. */
    private const int MIXED_LENGTH = 5;

    /**
     * Un recorrido que, de izquierda a derecha, encuentra o una serie de cifras
     * (con la posicion tecnica que la precede, si la hay) o una secuencia de
     * letras, marcas y cifras. Ver el docblock de la clase.
     */
    private const string TOKEN = '/(?<prefix>\b(?i:line|l[ií]nea)\s+|\.(?:php|js|mjs|cjs|ts|tsx|vue)(?::\d{1,6})?:|#)?'
        .'(?<series>(?<![\p{L}\p{M}\p{N}])\+?\d+(?:[ .\/\-]\d+)*(?![\p{L}\p{M}\p{N}]))'
        .'|(?<token>[\p{L}\p{M}\p{N}]+)/u';

    /**
     * Pasos 4 y 5 sobre un texto ya protegido y ya pasado por los patrones.
     */
    public static function apply(string $text): string
    {
        $filtered = preg_replace_callback(
            self::TOKEN,
            self::replace(...),
            $text,
            flags: PREG_UNMATCHED_AS_NULL,
        );

        if ($filtered === null) {
            return '';
        }

        return preg_replace('/…(?:\s+…)+/u', self::UNKNOWN_WORD, $filtered) ?? '';
    }

    /**
     * @param  array<int|string, string|null>  $match
     */
    private static function replace(array $match): string
    {
        $token = $match['token'] ?? null;

        if ($token !== null) {
            return self::token($token);
        }

        return self::series($match['prefix'] ?? '', $match['series'] ?? '');
    }

    /**
     * Una serie de grupos de cifras, con la posicion tecnica que la precede.
     */
    private static function series(string $prefix, string $series): string
    {
        $digits = preg_match_all('/\d/u', $series);

        if ($digits >= self::SERIES_DIGITS) {
            return $prefix.self::NUMBER;
        }

        // H3: un numero de linea es UN grupo corto. Con siete cifras ya ha
        // salido por arriba, asi que aqui solo falta exigir que sea uno.
        if ($prefix !== '' && preg_match('/^\d{1,'.self::TECHNICAL_DIGITS.'}$/', $series) === 1) {
            return $prefix.$series;
        }

        return $prefix.(preg_replace('/\d{4,}/u', self::NUMBER, $series) ?? '');
    }

    /**
     * Una secuencia de letras, marcas y cifras sin separadores.
     */
    private static function token(string $token): string
    {
        if (preg_match('/\d{4}/u', $token) === 1) {
            return self::NUMBER;
        }

        $hasDigits = preg_match_all('/\d/u', $token);

        if ($hasDigits > 0 && ErrorVocabulary::contains($token)) {
            return $token;
        }

        if ($hasDigits >= self::MIXED_DIGITS
            && mb_strlen($token) >= self::MIXED_LENGTH
            && preg_match('/\p{L}/u', $token) === 1) {
            return self::NUMBER;
        }

        return preg_replace_callback(
            '/[\p{L}\p{M}]+/u',
            static fn (array $letters): string => self::word($letters[0]),
            $token,
        ) ?? '';
    }

    /**
     * Una secuencia de letras: se queda si esta entera en el vocabulario, si
     * lo estan todas sus partes, o si es una sola letra latina.
     */
    private static function word(string $letters): string
    {
        if (ErrorVocabulary::contains($letters)) {
            return $letters;
        }

        $parts = preg_split('/(?<=\p{Ll})(?=\p{Lu})|(?<=\p{Lu})(?=\p{Lu}\p{Ll})/u', $letters);

        // Falla cerrado: sin partes no se puede afirmar que este en la lista.
        if ($parts === false) {
            return self::UNKNOWN_WORD;
        }

        foreach ($parts as $part) {
            if (! self::isKnownPart($part)) {
                return self::UNKNOWN_WORD;
            }
        }

        return $letters;
    }

    private static function isKnownPart(string $part): bool
    {
        $folded = ErrorVocabulary::fold($part);

        if (mb_strlen($folded) === 1) {
            return preg_match('/^[a-z]$/', $folded) === 1;
        }

        return ErrorVocabulary::contains($folded);
    }
}
