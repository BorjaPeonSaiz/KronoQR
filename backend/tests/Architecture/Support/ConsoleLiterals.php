<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

/**
 * Los literales de una orden de consola que parecen texto para una persona, y
 * las claves de traduccion que piden.
 *
 * Una orden que el operador del hotel lee —la purga, las credenciales, la
 * licencia, el soporte— escribe en el idioma de la instalacion, como el resto
 * del producto (ADR-017): sus textos viven en `lang/{es,en}` y se piden por
 * clave. Lo que se busca aqui es lo contrario: un literal con prosa dentro del
 * codigo de la orden.
 *
 * QUE CUENTA COMO PROSA. Un literal con un espacio y al menos una palabra de
 * tres minusculas que no forma parte de un identificador: «Licencia de
 * KronoQR», «Cliente: », «%-22s contratado: %-6s». No lo son una clave de
 * traduccion (`console.license_show.title`), un formato sin palabras
 * (`%-22s %s`), un identificador tecnico (`  retention.purge_executed #`,
 * ` (SQLSTATE %s)`) ni una linea para scripts con la forma `clave=valor`
 * (`revoked_credentials=%d employees=%d`), que es la que `update.sh` busca por
 * patron y no se traduce.
 *
 * QUE NO SE MIRA, y por que:
 *
 * - `$signature` y `$description`: son la ayuda de `php artisan list`, que
 *   Laravel declara como propiedades estaticas, y no se traducen en ningun
 *   comando del producto.
 * - las claves de un array (`'hash' => …`): son la forma de un JSON, no texto.
 * - los argumentos de una llamada al log tecnico (`Log::`, `$this->logger->`):
 *   el log va sin traducir y sin datos personales (doc 02 §8.1).
 * - el mensaje de una excepcion (`new RuntimeException('…')`): es el detalle
 *   tecnico que acaba en el log y en `error_events`, no la salida de la orden.
 */
final class ConsoleLiterals
{
    /** Propiedades cuyo valor es ayuda de Artisan, no salida de la orden. */
    private const array HELP_PROPERTIES = ['$signature', '$description', '$help'];

    /**
     * Los literales con prosa de un fichero, como `linea «texto»`.
     *
     * @param  list<string>  $allowed  Literales exactos que se dejan pasar, cada uno con su motivo donde se declara.
     * @return list<string>
     */
    public static function proseIn(string $code, array $allowed = []): array
    {
        /** @var list<array{0: int, 1: string, 2: int}|string> $tokens */
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn (array|string $token): bool => ! \is_array($token)
                || ! \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $found = [];
        $inHelp = false;

        foreach ($tokens as $i => $token) {
            // Desde `$signature =` hasta su `;`: la ayuda de Artisan.
            $inHelp = $token !== ';' && ($inHelp || self::isHelpProperty($token));
            $literal = $inHelp ? null : self::literalAt($tokens, $i);

            if ($literal === null || ! self::isProse($literal['text']) || \in_array($literal['text'], $allowed, true)
                || self::insideIgnoredCall($tokens, $i)) {
                continue;
            }

            $found[] = $literal['line'].' «'.$literal['text'].'»';
        }

        return $found;
    }

    /** @param  array{0: int, 1: string, 2: int}|string  $token */
    private static function isHelpProperty(array|string $token): bool
    {
        return \is_array($token) && $token[0] === T_VARIABLE && \in_array($token[1], self::HELP_PROPERTIES, true);
    }

    /**
     * El literal de cadena en `$i`, salvo si es la clave de un array.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{line: int, text: string}|null
     */
    private static function literalAt(array $tokens, int $i): ?array
    {
        $token = $tokens[$i];
        $next = $tokens[$i + 1] ?? null;

        if (! \is_array($token) || ! \in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            || (\is_array($next) && $next[0] === T_DOUBLE_ARROW)) {
            return null;
        }

        return [
            'line' => $token[2],
            'text' => $token[0] === T_CONSTANT_ENCAPSED_STRING ? substr($token[1], 1, -1) : $token[1],
        ];
    }

    public static function isProse(string $text): bool
    {
        // `clave=valor`: lineas para scripts, no para personas.
        if (preg_match('~^\s*[a-z_]+=\S*(?:\s+[a-z_]+=\S*)*\s*$~', $text) === 1) {
            return false;
        }

        // Fuera lo que tiene forma de identificador o de formato: claves con
        // punto o guion bajo, opciones `--x`, huecos `%-6s`, rutas.
        $words = (string) preg_replace(
            ['~--[\w-]+~u', '~%[-+ 0#]*\d*(?:\.\d+)?[sdfu]~', '~[\w-]+(?:[./:_][\w-]+)+~u'],
            ' ',
            $text,
        );

        return str_contains($text, ' ') && preg_match('~\p{Ll}{3,}~u', $words) === 1;
    }

    /**
     * Las claves de traduccion escritas tal cual en una orden que habla por
     * `SpeaksInstallationLanguage`: `->text('x')`, `->texts('x')` y
     * `->paragraph('x')` con la seccion de su constante `TEXTS` delante, y
     * `->installationText('fichero.x')` con la clave completa. Las claves que
     * se componen en ejecucion (`'states.'.$valor`) no se pueden comprobar sin
     * ejecutar y se quedan fuera.
     *
     * @return list<string>
     */
    public static function translationKeysIn(string $code): array
    {
        $keys = [];

        if (preg_match('~const\s+string\s+TEXTS\s*=\s*\'([^\']+)\'~', $code, $section) === 1) {
            preg_match_all('~->(?:text|texts|paragraph)\(\s*\'([^\']+)\'\s*[,)]~', $code, $relative);

            foreach ($relative[1] as $key) {
                $keys[] = $section[1].'.'.$key;
            }
        }

        preg_match_all('~->installationText\(\s*\'([^\']+)\'\s*[,)]~', $code, $absolute);

        return array_values(array_unique([...$keys, ...$absolute[1]]));
    }

    /**
     * Si la clave existe en `lang/<locale>/`, leyendo el fichero y no por el
     * traductor: un traductor con idioma de respaldo daria por buena la clave
     * que solo existe en español.
     */
    public static function keyExists(string $langRoot, string $locale, string $key): bool
    {
        $segments = explode('.', $key);
        $file = $langRoot.'/'.$locale.'/'.array_shift($segments).'.php';

        if (! is_file($file)) {
            return false;
        }

        $node = require $file;

        foreach ($segments as $segment) {
            if (! \is_array($node) || ! \array_key_exists($segment, $node)) {
                return false;
            }

            $node = $node[$segment];
        }

        return \is_string($node) || \is_array($node);
    }

    /**
     * Si el literal en `$i` es argumento de una llamada al log tecnico o del
     * constructor de una excepcion.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function insideIgnoredCall(array $tokens, int $i): bool
    {
        $open = self::openingParenthesis($tokens, $i);

        if ($open === null || ! \is_array($tokens[$open - 1] ?? null)) {
            return false;
        }

        $callee = $tokens[$open - 1];
        $before = $tokens[$open - 2] ?? null;
        $owner = $tokens[$open - 3] ?? null;

        if (! \is_array($before)) {
            return false;
        }

        $isException = $before[0] === T_NEW && preg_match('~(?:Exception|Error)$~', $callee[1]) === 1;
        $isLogger = \in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
            && self::isLogger($owner);

        return $isException || $isLogger;
    }

    /** `Log::`, `$this->logger->`, `$log->`: el que recibe la llamada se llama asi. */
    private static function isLogger(mixed $owner): bool
    {
        return is_array($owner) && is_string($owner[1] ?? null) && str_contains(strtolower($owner[1]), 'log');
    }

    /**
     * La posicion del parentesis que abre la llamada en la que esta `$i`, o
     * `null` si no esta dentro de ninguna (en las 200 posiciones anteriores).
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function openingParenthesis(array $tokens, int $i): ?int
    {
        $depth = 0;

        for ($j = $i - 1; $j >= 0 && $j > $i - 200; $j--) {
            $depth += match ($tokens[$j]) {
                ')' => 1,
                '(' => -1,
                default => 0,
            };

            if ($depth < 0) {
                return $j;
            }
        }

        return null;
    }
}
