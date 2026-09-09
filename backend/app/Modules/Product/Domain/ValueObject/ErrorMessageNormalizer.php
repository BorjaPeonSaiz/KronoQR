<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Quita del mensaje **todo lo que cambia de una ocurrencia a otra**, para que la
 * huella agrupe (RF-PD-15, decision 4 de la ficha 5.12).
 *
 * ## Es la pieza delicada de la tarea, y la ficha lo dice
 *
 * *«Si deja pasar un identificador, la agrupacion no agrupa; si normaliza de
 * mas, junta errores distintos.»* Los dos fallos son caros y opuestos:
 *
 * - **Normalizar de menos**: el fallo de un endpoint durante un cambio de turno
 *   deja cuatrocientas filas —una por `scan_id`— en lugar de una con
 *   `occurrences: 400`, la tabla se llena de ruido y el error importante queda
 *   enterrado. Es el problema que esta tabla existe para evitar.
 * - **Normalizar de mas**: dos fallos distintos comparten fila y el segundo
 *   nunca se ve. Peor todavia, porque no deja rastro de que existe.
 *
 * El equilibrio elegido: **se sustituye lo que es un identificador por su forma
 * y no se toca ni una palabra**. Dos mensajes que se diferencien en algo que no
 * sea un numero, una fecha, una ruta o un identificador siguen siendo dos
 * huellas. Y el `exception_class` y el `file:line` entran ademas en la huella
 * (ver {@see ErrorFingerprint}), de modo que dos fallos con el mismo texto y
 * distinto origen no se confunden aunque el texto normalice igual.
 *
 * ## El orden es este y no otro
 *
 * Cada regla se come lo que la siguiente buscaria:
 *
 * 1. **UUID** — antes que el hexadecimal y que los numeros, que lo partirian en
 *    cinco trozos irreconocibles.
 * 2. **Instantes ISO-8601 y horas `HH:MM`** — antes que los numeros, que los
 *    dejarian como `<n>-<n>-<n>`, tecnicamente estable pero ilegible.
 * 3. **Direcciones IP** — antes que los numeros, por lo mismo.
 * 4. **Correos** — antes que las rutas, porque un `@` con puntos alrededor se
 *    parece a una ruta relativa.
 * 5. **Rutas absolutas** — antes que los numeros, porque una ruta suele llevar
 *    un identificador dentro.
 * 6. **Hexadecimales de ocho o mas** — huellas, `trace_id`, `scan_id` sin
 *    guiones, claves de idempotencia.
 * 7. **Todo numero** — la ultima, porque es la mas amplia.
 *
 * ## Es distinto del saneado, y los dos se aplican
 *
 * {@see ErrorMessageSanitizer} quita lo que **no puede salir** de la instalacion
 * y su resultado es lo que se guarda en `message`, legible. Esto quita lo que
 * **cambia entre repeticiones** y su resultado no se guarda en ninguna parte:
 * solo entra en el hash. El orden es sanear primero y normalizar despues, de
 * modo que la agrupacion no dependa de un dato personal que ya no esta.
 *
 * ## Dominio puro
 *
 * Sin framework y sin estado. La prueba unitaria que lo fija es la de la ficha:
 * dos mensajes con distinto UUID, distinto numero y distinta ruta producen la
 * misma huella.
 */
final readonly class ErrorMessageNormalizer
{
    /**
     * El mensaje sin identificadores variables. **No se guarda**: solo se
     * hashea.
     */
    public static function normalize(string $message): string
    {
        $normalized = (string) preg_replace('/\s+/u', ' ', trim($message));

        // 1. UUID en cualquiera de sus formas, incluida la de PostgreSQL en
        //    mayusculas.
        $normalized = (string) preg_replace(
            '/\b[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\b/',
            '<uuid>',
            $normalized,
        );

        // 2. Instantes ISO-8601 completos y, despues, horas sueltas.
        $normalized = (string) preg_replace(
            '/\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+\-]\d{2}:?\d{2})?)?/',
            '<time>',
            $normalized,
        );

        $normalized = (string) preg_replace('/\b\d{1,2}:\d{2}(:\d{2})?\b/', '<time>', $normalized);

        // 3. Direcciones IPv4 y IPv6.
        //
        //    La de IPv6 exige grupo hexadecimal a los DOS lados de cada `:`, y
        //    no es una precaucion teorica: con la forma laxa que admite grupos
        //    vacios, `Handler::method` casa —`::` son dos grupos vacios— y toda
        //    llamada estatica de un mensaje de PHP se convertiria en `<ip>`,
        //    juntando en una sola huella errores de metodos distintos.
        $normalized = (string) preg_replace('/\b\d{1,3}(\.\d{1,3}){3}\b/', '<ip>', $normalized);
        $normalized = (string) preg_replace('/\b([0-9a-fA-F]{1,4}:){2,7}[0-9a-fA-F]{1,4}\b/', '<ip>', $normalized);

        // 4. Correos. El saneado ya los habra convertido en `[email]` cuando se
        //    encadenan los dos, pero esta clase tambien se usa suelta en las
        //    pruebas y no puede suponerlo.
        $normalized = (string) preg_replace(
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
            '<email>',
            $normalized,
        );

        // 5. Rutas absolutas, de Unix y de Windows. Dos segmentos como minimo:
        //    con uno solo, un `/scan` cualquiera dejaria de distinguirse.
        $normalized = (string) preg_replace('/[A-Za-z]:\\\\[^\s"\']+/', '<path>', $normalized);
        $normalized = (string) preg_replace('/(?:\/[\w.\-@%+]+){2,}\/?/', '<path>', $normalized);

        // 6. Cadenas hexadecimales largas: huellas, trazas, identificadores de
        //    idempotencia sin guiones.
        $normalized = (string) preg_replace('/\b[0-9a-fA-F]{8,}\b/', '<hex>', $normalized);

        // 7. Todo numero. La ultima porque es la mas amplia: aqui ya no queda
        //    ningun numero que forme parte de otra cosa.
        return (string) preg_replace('/\d+/', '<n>', $normalized);
    }
}
