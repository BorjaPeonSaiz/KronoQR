<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Con que codificacion se escribe el fichero de nomina
 * (`PAYROLL_EXPORT_ENCODING`, RF-IN-07).
 *
 * ## Por que `latin1` sigue existiendo en 2026
 *
 * Porque los programas de nomina del sector llevan decadas instalados y varios
 * solo importan ISO-8859-1. Entregarles UTF-8 produce «Fernández» convertido en
 * basura dentro de la nomina de una persona, y la respuesta util no es «que
 * actualicen su programa»: es escribir el fichero como su programa lo espera.
 * Esto es exactamente lo que ADR-017 llama configuracion — la diferencia entre
 * clientes es dato, no codigo.
 *
 * ## Las tres, y que significa cada una
 *
 * - `utf8_bom` — UTF-8 con marca de orden de bytes. **El valor de serie.** El BOM
 *   es lo que le dice a Excel que el fichero es UTF-8; sin el, con configuracion
 *   regional española lo lee en Windows-1252 y los acentos salen rotos.
 * - `utf8` — UTF-8 **sin** BOM. Muchos importadores tratan el BOM como parte del
 *   primer nombre de columna y despues no encuentran la columna.
 * - `latin1` — ISO-8859-1. Lo que no cabe se **transcribe** —«á» pasa a «a»— y lo
 *   imposible se sustituye por `?`. Nunca falla: un fichero de nomina a medias es
 *   peor que uno con un caracter aproximado, y quien lo recibe lo compara con la
 *   ficha de todos modos.
 */
enum PayrollEncoding: string
{
    /** UTF-8 con BOM. El valor de serie. */
    case Utf8Bom = 'utf8_bom';

    /** UTF-8 sin BOM. */
    case Utf8 = 'utf8';

    /** ISO-8859-1, con transcripcion y `?` para lo imposible. */
    case Latin1 = 'latin1';

    /** Si el fichero empieza por la marca de orden de bytes. */
    public function hasByteOrderMark(): bool
    {
        return $this === self::Utf8Bom;
    }

    /** Si hay que transcodificar la salida desde UTF-8. */
    public function isLatin1(): bool
    {
        return $this === self::Latin1;
    }

    /**
     * El `charset` que se anuncia en `Content-Type`.
     *
     * Va ademas del BOM y no en su lugar: el BOM es para el programa que abre el
     * fichero descargado y esto para el que lo consuma por HTTP.
     */
    public function charset(): string
    {
        return $this->isLatin1() ? 'iso-8859-1' : 'utf-8';
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $encoding): string => $encoding->value, self::cases());
    }
}
