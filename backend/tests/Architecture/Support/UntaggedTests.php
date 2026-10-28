<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use App\Console\Commands\Quality\Support\TagScanner;
use PhpToken;

/**
 * Las pruebas que no declaran ningun requisito (RQ-13; V21-1-QA-1 de la 2.2.1).
 *
 * `qa:traceability --check` comprueba que cada requisito tenga alguna prueba,
 * no que cada prueba cite algun requisito: diecisiete pruebas sin etiqueta
 * vivieron hasta la 2.3.0 sin que nada lo impidiera, y una prueba sin etiqueta
 * no aparece en la matriz que se entrega como evidencia. Esto las encuentra.
 *
 * - **Pest** se lee con el tokenizador de PHP y no con expresiones regulares:
 *   las pruebas de las herramientas de calidad llevan fuentes de prueba dentro
 *   de cadenas (`it('…')` entre comillas), y solo el tokenizador distingue una
 *   llamada de un texto. Una prueba es una sentencia que empieza por `it(` o
 *   `test(`; esta etiquetada si su cadena lleva un `->group(` con una cadena
 *   con forma de requisito (`RF-…`, `RN-…`, `RQ-…`, `RNF-…`).
 * - **Playwright** se lee como texto, como hace {@see TagScanner}:
 *   `test('…', { tag: [...] }, …)`. Vale una etiqueta literal con forma de
 *   requisito o una lista que se expande (`[...pass.requirements]`, tipada
 *   como `` `@${string}`[] ``): la de las pasadas de axe, que llevan el
 *   requisito de su pantalla.
 */
final class UntaggedTests
{
    /** Lo que alguien ha querido escribir como requisito. */
    private const string REQUIREMENT = '/^@?R[A-Z]{0,3}-[\w.-]+$/';

    /** `test(` sin `.` delante: ni `test.describe(`, ni `test.step(`, ni `foo.test(`. */
    private const string PLAYWRIGHT_DECLARATION = '/(?<![\w.$])test\s*\(/';

    /**
     * La declaracion entera hasta su objeto de opciones con `tag`. El nombre
     * puede ir partido en varias cadenas unidas con `+`.
     */
    private const string PLAYWRIGHT_TAGGED = '/^test\s*\(\s*(?:\'[^\'\n]*\'|"[^"\n]*"|`[^`]*`)(?:\s*\+\s*(?:\'[^\'\n]*\'|"[^"\n]*"|`[^`]*`))*\s*,\s*\{[^{}]*\btag\s*:\s*(?:\[[^\]]*(?:[\'"`]@R[A-Z]{0,3}-|\.\.\.)[^\]]*\]|[\'"`]@R[A-Z]{0,3}-)/';

    /**
     * Cadenas y comentarios de TypeScript. Solo se borran los comentarios —un
     * `test()` citado en uno no es una prueba—; las cadenas se dejan, que son
     * los nombres. Las cadenas van primero en la alternancia porque una URL
     * entre comillas no es media linea comentada.
     */
    private const string TYPESCRIPT_LITERAL = '/\'(?:\\\\.|[^\'\\\\\n])*\'|"(?:\\\\.|[^"\\\\\n])*"|`(?:\\\\.|[^`\\\\])*`|\/\/[^\n]*|\/\*.*?\*\//s';

    /**
     * Las pruebas de Pest sin etiqueta de requisito, con su linea y su nombre.
     *
     * @return list<array{line: int, name: string}>
     */
    public static function pest(string $source): array
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));
        $untagged = [];

        foreach ($tokens as $index => $token) {
            if (! self::startsPestTest($tokens, $index)) {
                continue;
            }

            $statement = self::statementFrom($tokens, $index);

            if (! self::pestChainCitesRequirement($statement)) {
                $untagged[] = ['line' => $token->line, 'name' => self::firstString($statement)];
            }
        }

        return $untagged;
    }

    /**
     * Las pruebas de Playwright sin etiqueta de requisito, con su linea.
     *
     * @return list<array{line: int, name: string}>
     */
    public static function playwright(string $source): array
    {
        $source = (string) preg_replace_callback(
            self::TYPESCRIPT_LITERAL,
            static fn (array $literal): string => str_starts_with($literal[0], '/')
                ? (string) preg_replace('/[^\n]/', ' ', $literal[0])
                : $literal[0],
            $source,
        );

        preg_match_all(self::PLAYWRIGHT_DECLARATION, $source, $matches, PREG_OFFSET_CAPTURE);
        $untagged = [];

        foreach ($matches[0] as [, $offset]) {
            $declaration = substr($source, $offset, 2000);

            if (preg_match(self::PLAYWRIGHT_TAGGED, $declaration) !== 1) {
                preg_match('/^test\s*\(\s*([\'"`])(?<name>.*?)\1/s', $declaration, $name);
                $untagged[] = ['line' => substr_count(substr($source, 0, $offset), "\n") + 1, 'name' => $name['name'] ?? ''];
            }
        }

        return $untagged;
    }

    /**
     * `it(`/`test(` al principio de una sentencia: no un metodo (`->it(`), no
     * un estatico (`X::test(`), no una declaracion (`function test(`).
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function startsPestTest(array $tokens, int $index): bool
    {
        $token = $tokens[$index];

        if (! $token->is(T_STRING) || ! \in_array($token->text, ['it', 'test'], true)) {
            return false;
        }

        $next = $tokens[$index + 1] ?? null;
        $previous = $tokens[$index - 1] ?? null;

        return $next !== null && $next->text === '('
            && ($previous === null || $previous->is(T_OPEN_TAG) || \in_array($previous->text, [';', '{', '}'], true));
    }

    /**
     * Los tokens de la sentencia que empieza en `$start`, hasta su `;`.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<PhpToken>
     */
    private static function statementFrom(array $tokens, int $start): array
    {
        $depth = 0;
        $statement = [];

        foreach (\array_slice($tokens, $start) as $token) {
            $statement[] = $token;
            $depth += self::nesting($token);

            if ($depth === 0 && $token->text === ';') {
                break;
            }
        }

        return $statement;
    }

    /**
     * Cuanto abre (+1) o cierra (-1) un token: parentesis, corchetes, llaves,
     * atributos (`#[`) e interpolaciones (`{$x}`, `${x}`).
     */
    private static function nesting(PhpToken $token): int
    {
        if ($token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE])) {
            return 1;
        }

        return match ($token->text) {
            '(', '[', '{' => 1,
            ')', ']', '}' => -1,
            default => 0,
        };
    }

    /**
     * ¿Lleva la cadena de la llamada —lo que va detras de su `)`— un
     * `->group(` con alguna cadena con forma de requisito?
     *
     * @param  list<PhpToken>  $statement
     */
    private static function pestChainCitesRequirement(array $statement): bool
    {
        $chain = self::chainAfterCall($statement);

        foreach ($chain as $index => $token) {
            if (self::isGroupCall($chain, $index) && self::argumentsCiteRequirement(\array_slice($chain, $index + 1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lo que va detras del `)` que cierra la llamada `it(…)`: `->with(…)`,
     * `->group(…)`, `->skip(…)`.
     *
     * @param  list<PhpToken>  $statement
     * @return list<PhpToken>
     */
    private static function chainAfterCall(array $statement): array
    {
        $depth = 0;

        foreach ($statement as $index => $token) {
            $depth += self::nesting($token);

            if ($depth === 0 && $token->text === ')') {
                return \array_slice($statement, $index + 1);
            }
        }

        return [];
    }

    /**
     * @param  list<PhpToken>  $chain
     */
    private static function isGroupCall(array $chain, int $index): bool
    {
        return $chain[$index]->is(T_STRING) && $chain[$index]->text === 'group'
            && ($chain[$index - 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) === true;
    }

    /**
     * Las cadenas literales de la lista de argumentos que abre `$tokens[0]`.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function argumentsCiteRequirement(array $tokens): bool
    {
        $depth = 0;

        foreach ($tokens as $token) {
            $depth += match ($token->text) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if ($token->is(T_CONSTANT_ENCAPSED_STRING) && preg_match(self::REQUIREMENT, substr($token->text, 1, -1)) === 1) {
                return true;
            }

            if ($depth === 0) {
                return false;
            }
        }

        return false;
    }

    /**
     * @param  list<PhpToken>  $statement
     */
    private static function firstString(array $statement): string
    {
        foreach ($statement as $token) {
            if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                return substr($token->text, 1, -1);
            }
        }

        return '';
    }
}
