<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use Normalizer;
use RuntimeException;

/**
 * Las secciones de `frequent-person-names.txt` (`# --- Titulo ---`), para
 * comprobar el TAMANO que exige H5 del dictamen de seguridad del bloque 19: al
 * menos 1 000 nombres de hombre, 1 000 de mujer y 1 000 apellidos de Espana.
 *
 * {@see FrequentPersonNames} da el conjunto entero, que es lo que se cruza con el
 * vocabulario; esto solo sirve para que nadie lo encoja sin que una prueba lo vea.
 */
final class FrequentPersonNameSections
{
    /**
     * Palabras distintas de una seccion, plegadas (minusculas y sin tildes), para
     * que «José» y «Jose» cuenten una vez.
     *
     * @return list<string>
     */
    public static function words(string $title): array
    {
        $lines = file(FrequentPersonNames::FILE, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new RuntimeException('No se puede leer '.FrequentPersonNames::FILE.'.');
        }

        $current = null;
        $words = [];

        foreach ($lines as $line) {
            if (preg_match('/^# --- (.+?) -+\s*$/u', $line, $header) === 1) {
                $current = $header[1];

                continue;
            }

            if ($current !== $title || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            preg_match_all('/[\p{L}\p{M}]+/u', $line, $matches);

            foreach ($matches[0] as $word) {
                $folded = mb_strtolower((string) preg_replace('/\p{M}+/u', '', Normalizer::normalize($word, Normalizer::FORM_D) ?: $word));
                $words[$folded] = true;
            }
        }

        return array_map(strval(...), array_keys($words));
    }
}
