<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use RuntimeException;

/**
 * Lee `frequent-person-names.txt`: nombres y apellidos frecuentes de listas
 * publicas de frecuencia, para `ErrorVocabularyTest` (ADR-048, H5).
 *
 * El conjunto de datos vive en un fichero de texto, una entrada por linea, para
 * que crezca **sin tocar la prueba**: ampliarlo es anadir lineas. Un nombre
 * compuesto cuenta como sus palabras sueltas.
 */
final class FrequentPersonNames
{
    public const string FILE = __DIR__.'/frequent-person-names.txt';

    /**
     * Las palabras del conjunto, tal y como estan escritas (con mayusculas y
     * tildes), sin repetir.
     *
     * @return list<string>
     */
    public static function words(): array
    {
        $lines = file(self::FILE, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new RuntimeException('No se puede leer '.self::FILE.'.');
        }

        $words = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            preg_match_all('/[\p{L}\p{M}]+/u', $line, $matches);

            foreach ($matches[0] as $word) {
                $words[$word] = true;
            }
        }

        return array_map(strval(...), array_keys($words));
    }
}
