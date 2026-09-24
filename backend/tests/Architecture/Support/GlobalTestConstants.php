<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use PhpToken;

/*
 * Las constantes globales declaradas por los ficheros de prueba.
 *
 * POR QUE EXISTE. Un fichero de Pest declara sus constantes a nivel de fichero,
 * y ahi **no hay espacio de nombres que las separe**: `const AHORA_DEL_FICHAJE`
 * en dos ficheros de la misma suite es la misma constante declarada dos veces.
 * Paso en el cierre de la Fase 3. PHP emite un aviso de redeclaracion mientras
 * Pest carga los ficheros —antes de imprimir nada—, el proceso termina con
 * codigo 1 y la salida no menciona ni la constante, ni el fichero, ni la prueba:
 * la suite se pone en rojo sin decir por que, y el rato que se pierde buscando
 * el fallo en la ultima prueba tocada no lo recupera nadie.
 *
 * LA RECETA. La constante lleva el prefijo del fichero que la declara:
 * `SCAN_IDEMPOTENCY_AHORA_DEL_FICHAJE` en `ScanIdempotencyTest.php`. No es
 * burocracia: en la salida de un fallo, el prefijo dice de donde sale el valor.
 *
 * OJO A LA ASIMETRIA CON `IdentifierLanguageTest`: en `tests/` el idioma del
 * escenario es bienvenido —`AHORA_DEL_FICHAJE` esta bien escrito— y lo que se
 * exige es el prefijo. Son dos reglas distintas sobre dos arboles distintos.
 */
final class GlobalTestConstants
{
    /**
     * Las constantes declaradas A NIVEL DE FICHERO, con su linea.
     *
     * Solo las de nivel de fichero: las de clase, interfaz o enum viven dentro de
     * un espacio de nombres y de un tipo, y dos con el mismo nombre no chocan.
     * Se distingue por PROFUNDIDAD DE LLAVES: el cuerpo de una clase o de una
     * funcion esta dentro de `{}` y el de un fichero no. Se cuenta sobre los
     * tokens, de modo que un `{` dentro de una cadena o de un comentario no
     * cuenta —que es justo lo que le pasaria a una expresion regular—.
     *
     * @return list<array{name: string, line: int}>
     */
    public static function declaredIn(string $file): array
    {
        return self::declaredInSource((string) file_get_contents($file));
    }

    /**
     * Lo mismo sobre codigo fuente en memoria, para poder ejercitar la
     * heuristica con fragmentos escritos a mano.
     *
     * Los fragmentos no pueden ser ficheros de apoyo dentro de `tests/`: dos
     * ficheros de fixture que declararan la misma constante serian **un
     * duplicado de verdad** y pondrian en rojo la prueba que vienen a
     * comprobar.
     *
     * @return list<array{name: string, line: int}>
     */
    public static function declaredInSource(string $source): array
    {
        $tokens = PhpToken::tokenize($source);
        $depth = 0;
        $declared = [];

        foreach ($tokens as $index => $token) {
            $depth += self::braceDelta($token);

            if ($depth !== 0 || ! $token->is(T_CONST)) {
                continue;
            }

            $declared = [...$declared, ...self::constantNamesFrom($tokens, $index)];
        }

        return $declared;
    }

    /**
     * Cuanto abre o cierra este token.
     *
     * `${` y el `{` de la interpolacion de cadenas cierran con `}` igual que los
     * demas, asi que contarlos a todos mantiene el equilibrio. Una cuenta que se
     * olvidara de ellos quedaria descuadrada a partir de la primera cadena
     * interpolada y dejaria de ver las constantes que vinieran detras.
     */
    private static function braceDelta(PhpToken $token): int
    {
        return match ($token->text) {
            '{', '${' => 1,
            '}' => -1,
            default => 0,
        };
    }

    /**
     * Los nombres declarados por el `const` que empieza en esa posicion.
     *
     * `const A = 1, B = 2;` y las constantes tipadas (`const string A = …`): el
     * nombre es el `T_STRING` que va pegado al `=`.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return list<array{name: string, line: int}>
     */
    private static function constantNamesFrom(array $tokens, int $index): array
    {
        $names = [];

        for ($i = $index + 1, $count = \count($tokens); $i < $count && $tokens[$i]->text !== ';'; $i++) {
            if (! $tokens[$i]->is(T_STRING)) {
                continue;
            }

            if (self::nextSignificant($tokens, $i)?->text === '=') {
                $names[] = ['name' => $tokens[$i]->text, 'line' => $tokens[$i]->line];
            }
        }

        return $names;
    }

    /**
     * Las constantes globales declaradas por mas de un fichero bajo una ruta.
     *
     * La clave es el nombre de la constante y el valor, la lista de ficheros que
     * la declaran, relativos a esa ruta. Un nombre declarado una sola vez no
     * aparece.
     *
     * @return array<string, list<string>>
     */
    public static function duplicatesUnder(string $directory): array
    {
        $byName = [];

        foreach (ModuleTree::phpFilesUnder($directory) as $file) {
            foreach (self::declaredIn($file) as $constant) {
                $byName[$constant['name']][] = ModuleTree::relative($file, $directory).':'.$constant['line'];
            }
        }

        return array_filter($byName, static fn (array $files): bool => \count($files) > 1);
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private static function nextSignificant(array $tokens, int $index): ?PhpToken
    {
        for ($i = $index + 1, $count = \count($tokens); $i < $count; $i++) {
            if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $tokens[$i];
            }
        }

        return null;
    }
}
