<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

/**
 * Lo que vera una persona cuando el dominio rechaza algo: el texto de la clave
 * de traduccion en cada idioma del producto, con sus marcadores sustituidos.
 *
 * El dominio no traduce: lleva `translationKey` y `parameters`, y el borde HTTP
 * los resuelve con `__()` en el idioma negociado. Dos fallos no los avisa nadie:
 * si la clave no existe, Laravel devuelve la propia clave y la pantalla dice
 * «settings.errors.too_long»; si falta un parametro, el texto sale con
 * «:maximum» literal. Esto los detecta leyendo `lang/` directamente, sin
 * levantar el framework, para que las pruebas unitarias de las excepciones
 * puedan comprobarlo.
 */
final class RejectionTranslations
{
    /** Los idiomas que el producto trae de serie (ver `LangParityTest`). */
    private const array LOCALES = ['es', 'en'];

    /**
     * @param  array<string, string|int>  $parameters
     * @return list<string> vacia si el texto sale completo en todos los idiomas
     */
    public static function problemsWith(string $translationKey, array $parameters): array
    {
        $problems = [];

        foreach (self::LOCALES as $locale) {
            $text = self::text($locale, $translationKey);

            if ($text === null) {
                $problems[] = 'lang/'.$locale.' no tiene texto para '.$translationKey;

                continue;
            }

            foreach (self::placeholders($text) as $placeholder) {
                if (! \array_key_exists($placeholder, $parameters)) {
                    $problems[] = 'lang/'.$locale.' usa :'.$placeholder.' en '.$translationKey.' y el rechazo no lo trae';
                }
            }
        }

        return $problems;
    }

    private static function text(string $locale, string $translationKey): ?string
    {
        $segments = explode('.', $translationKey);
        $file = \dirname(__DIR__, 3).'/lang/'.$locale.'/'.array_shift($segments).'.php';

        if (! is_file($file)) {
            return null;
        }

        /** @var mixed $value */
        $value = require $file;

        foreach ($segments as $segment) {
            if (! \is_array($value) || ! \array_key_exists($segment, $value)) {
                return null;
            }

            /** @var mixed $value */
            $value = $value[$segment];
        }

        return \is_string($value) ? $value : null;
    }

    /**
     * Laravel sustituye `:name`, `:Name` y `:NAME` con el mismo parametro.
     *
     * @return list<string>
     */
    private static function placeholders(string $text): array
    {
        preg_match_all('/:([A-Za-z][A-Za-z_]*)/', $text, $matches);

        return array_values(array_unique(array_map(strtolower(...), $matches[1])));
    }
}
