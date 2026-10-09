<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

/**
 * Las llamadas a los mensajes del catalogo de los scripts de operacion
 * (`kq_format`, `kq_msg`, `kq_text`) y sus argumentos, contados como los
 * contaria bash (V3-PL-04).
 *
 * No es un analizador de shell completo: separa palabras respetando comillas
 * simples y dobles, escapes, continuaciones de linea y expansiones `$( )` y
 * `${ }` anidadas, y para al final de la orden. Es lo que aparece en las
 * llamadas de los scripts; `ScriptMessageArgumentsTest` comprueba los casos.
 */
final class ShellMessageCalls
{
    private const string WORD_BREAK = " \t\n);|&<>`";

    private const string COMMAND_END = "\n);|&<>#`";

    /**
     * Las llamadas con clave literal de un conjunto de ficheros:
     * [fichero:linea, funcion, clave, numero de argumentos o null si varia].
     *
     * @param  list<string>  $files
     * @return list<array{0: string, 1: string, 2: string, 3: int|null}>
     */
    public static function in(array $files, string $root): array
    {
        $calls = [];
        foreach ($files as $file) {
            foreach (self::inSource((string) file_get_contents($file)) as [$line, $function, $key, $arguments]) {
                $calls[] = [str_replace($root, '', $file).':'.$line, $function, $key, $arguments];
            }
        }

        return $calls;
    }

    /**
     * @return list<array{0: int, 1: string, 2: string, 3: int|null}>
     */
    public static function inSource(string $source): array
    {
        preg_match_all('/(?<![\w-])(kq_format|kq_msg|kq_text)[ \t]+/', $source, $matches, PREG_OFFSET_CAPTURE);
        $calls = [];

        foreach ($matches[0] as $index => [$text, $offset]) {
            $words = self::isCommented($source, $offset) ? [] : self::words($source, $offset + \strlen($text));
            $key = trim($words[0] ?? '', "\"'");
            // Sin clave literal es un envoltorio (`kq_format "${key}" "$@"`), no una llamada.
            if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
                continue;
            }
            $arguments = \array_slice($words, 1);
            $calls[] = [
                substr_count(substr($source, 0, $offset), "\n") + 1,
                $matches[1][$index][0],
                $key,
                self::isVariadic($arguments) ? null : \count($arguments),
            ];
        }

        return $calls;
    }

    /**
     * Las palabras de una orden desde $i hasta su final.
     *
     * @return list<string>
     */
    public static function words(string $s, int $i): array
    {
        $words = [];
        $n = \strlen($s);
        while (($i = self::skipBlanks($s, $i)) < $n && ! str_contains(self::COMMAND_END, $s[$i])) {
            $end = $i;
            while ($end < $n && ! str_contains(self::WORD_BREAK, $s[$end])) {
                $end = self::step($s, $end);
            }
            $words[] = substr($s, $i, $end - $i);
            $i = $end;
        }

        return $words;
    }

    /** @param list<string> $arguments */
    private static function isVariadic(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if (preg_match('/\$@|\$\*|\[@\]|\[\*\]/', $argument) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function isCommented(string $source, int $offset): bool
    {
        $lineStart = strrpos(substr($source, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;

        return str_starts_with(ltrim(substr($source, $lineStart, $offset - $lineStart)), '#');
    }

    private static function skipBlanks(string $s, int $i): int
    {
        $n = \strlen($s);
        while ($i < $n) {
            if ($s[$i] === ' ' || $s[$i] === "\t") {
                $i++;
            } elseif ($s[$i] === '\\' && ($s[$i + 1] ?? '') === "\n") {
                $i += 2;
            } else {
                break;
            }
        }

        return $i;
    }

    /** La posicion siguiente a la unidad que empieza en $i. */
    private static function step(string $s, int $i): int
    {
        return match (true) {
            $s[$i] === '\\' => $i + 2,
            $s[$i] === "'" => self::afterSingleQuote($s, $i),
            $s[$i] === '"' => self::afterDoubleQuote($s, $i),
            self::opensExpansion($s, $i) => self::afterExpansion($s, $i),
            default => $i + 1,
        };
    }

    private static function opensExpansion(string $s, int $i): bool
    {
        return $s[$i] === '$' && \in_array($s[$i + 1] ?? '', ['(', '{'], true);
    }

    private static function afterSingleQuote(string $s, int $i): int
    {
        $end = strpos($s, "'", $i + 1);

        return $end === false ? \strlen($s) : $end + 1;
    }

    private static function afterDoubleQuote(string $s, int $i): int
    {
        $n = \strlen($s);
        $i++;
        while ($i < $n && $s[$i] !== '"') {
            $i = match (true) {
                $s[$i] === '\\' => $i + 2,
                self::opensExpansion($s, $i) => self::afterExpansion($s, $i),
                default => $i + 1,
            };
        }

        return $i + 1;
    }

    private static function afterExpansion(string $s, int $i): int
    {
        $open = $s[$i + 1];
        $close = $open === '(' ? ')' : '}';
        $n = \strlen($s);
        $depth = 1;
        $i += 2;
        while ($i < $n && $depth > 0) {
            $c = $s[$i];
            $depth += match ($c) {
                $open => 1,
                $close => -1,
                default => 0,
            };
            $i = $c === $open || $c === $close ? $i + 1 : self::step($s, $i);
        }

        return $i;
    }
}
