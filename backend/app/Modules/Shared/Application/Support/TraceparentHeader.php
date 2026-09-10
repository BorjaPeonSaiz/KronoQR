<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Support;

/**
 * La cabecera `traceparent` del W3C, leida en UN solo sitio (doc 02 §8.1).
 *
 * ## Por que existe
 *
 * La misma expresion regular y el mismo «un identificador a ceros no es un
 * identificador» aparecian en tres copias —el middleware de propagacion, el
 * captador de errores de servidor (RF-PD-15) y el processor de correlacion del
 * log—, y las tres **no decian lo mismo**: una recortaba `'0'`, otra `'0 '`
 * (ceros Y espacios), y la tercera ni siquiera comprobaba la forma de la
 * cabecera antes de publicarla. Tres respuestas distintas a «que `trace_id` se
 * escribe» es exactamente lo que impide unir una linea de log con su traza.
 *
 * Aqui hay una sola, y todo lo que necesite parsear un `traceparent` la usa.
 *
 * ## Se valida la forma ANTES de creer nada de lo que trae
 *
 * `traceparent` es una cabecera de red: la escribe quien quiera y con lo que
 * quiera. Un valor que no case con el formato del W3C no se propaga, no se
 * guarda y no se escribe en ninguna parte — ni siquiera truncado. Una cabecera
 * arbitraria copiada al `Context` de Laravel acaba en cada linea de log y de ahi
 * en Loki, donde se queda el plazo de retencion entero (RL-11, regla dura 21).
 *
 * ## Nada de aqui lanza
 *
 * Sin cabecera, con una cabecera rota o con un identificador a ceros se devuelve
 * `null`, que es una respuesta perfectamente utilizable (regla dura 19).
 */
final class TraceparentHeader
{
    /**
     * `00-<32 hex>-<16 hex>-<2 hex>`, la forma que fija el W3C.
     *
     * Anclada con `\A` y `\z`, no con `^` y `$`, y no es purismo: en PCRE `$`
     * casa **tambien justo antes de un salto de linea final**, asi que
     * `"00-…-01\n…"` pasaba por bueno. Una cabecera que se da por valida se
     * publica en el `Context` y de ahi va a cada linea de log y a Loki: un salto
     * de linea colado ahi parte la linea JSON en dos.
     */
    public const string PATTERN = '/\A[0-9a-f]{2}-([0-9a-f]{32})-[0-9a-f]{16}-[0-9a-f]{2}\z/';

    /** El nombre de la cabecera y de la clave homonima del `Context` de Laravel. */
    public const string NAME = 'traceparent';

    /** Si el valor tiene la forma exacta que fija el W3C. */
    public static function valid(mixed $traceparent): bool
    {
        return is_string($traceparent) && preg_match(self::PATTERN, $traceparent) === 1;
    }

    /**
     * El `trace_id` que lleva dentro una cabecera `traceparent`, o `null` si la
     * cabecera no tiene la forma del W3C o si el identificador no es
     * significativo.
     *
     * Es lo que permite fechar un apunte con la traza del cliente aunque esta
     * instalacion no exporte trazas — que es la de la mayoria.
     */
    public static function traceIdOf(mixed $traceparent): ?string
    {
        if (! is_string($traceparent) || preg_match(self::PATTERN, $traceparent, $matches) !== 1) {
            return null;
        }

        return self::significant($matches[1]);
    }

    /**
     * Un `trace_id` a ceros es el que devuelve un span inerte: escribirlo seria
     * peor que no escribir nada, porque **parece** un identificador y nadie lo
     * buscaria dos veces.
     *
     * Se recortan ceros **y espacios**: una de las tres copias que esta clase
     * sustituye recortaba solo ceros y daba por bueno `'0 '`.
     */
    public static function significant(mixed $traceId): ?string
    {
        if (! is_string($traceId) || trim($traceId, '0 ') === '') {
            return null;
        }

        return $traceId;
    }
}
