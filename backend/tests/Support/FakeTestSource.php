<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Construye codigo de prueba de mentira para los fixtures de las pruebas de
 * `qa:traceability`, SIN que la llamada a la etiqueta aparezca nunca escrita de
 * forma literal en un fichero de tests/.
 *
 * Por que hace falta esta gimnasia. El escaner lee tests/ COMO TEXTO: no
 * distingue una prueba real de un ejemplo dentro de un heredoc. Un
 * `->group('RN-05')` escrito literalmente en un fixture haria figurar RN-05
 * como cubierto en la matriz de trazabilidad por una prueba que no existe.
 *
 * Es exactamente el fallo que esas pruebas comprueban, cometido al comprobarlo:
 * la matriz se entrega como evidencia de que cada obligacion legal esta
 * verificada, y un fixture es una prueba que nunca se ejecuta. Concatenando el
 * nombre del metodo, la cadena `->group(` no existe en ningun fichero de
 * tests/ salvo en las etiquetas de verdad.
 *
 * Vive en tests/Support/ y no dentro de una suite a proposito: phpunit.xml solo
 * recoge Unit, Integration, Feature, Contract y Architecture, asi que este
 * fichero no se ejecuta como prueba.
 */
final class FakeTestSource
{
    private const string GROUP = 'group';

    private const string PLAYWRIGHT_TAG = 'tag';

    /**
     * `it('nombre')->group('RN-05')...;`
     *
     * @param  list<string>  $requirements
     * @param  string  $chained  Lo que va detras de la etiqueta: `->skip('...')`.
     * @param  string  $before  Lo que va delante: `->skip('...')`.
     */
    public static function pest(string $name, array $requirements, string $chained = '', string $before = ''): string
    {
        $arguments = implode(', ', array_map(
            static fn (string $requirement): string => "'".$requirement."'",
            $requirements,
        ));

        return "it('".$name."')".$before.'->'.self::GROUP.'('.$arguments.')'.$chained.";\n";
    }

    /**
     * Un fichero PHP completo con varias pruebas de mentira dentro.
     *
     * @param  list<string>  $statements
     */
    public static function file(array $statements): string
    {
        return "<?php\n".implode('', $statements);
    }

    /** `#[Group('RN-06')]` sobre un metodo de PHPUnit. */
    public static function attribute(string $requirement): string
    {
        return "<?php\n#[".ucfirst(self::GROUP)."('".$requirement."')]\npublic function testAlgo(): void {}\n";
    }

    /** `test('nombre', { tag: ['@RF-KI-03'] }, ...)` de Playwright. */
    public static function playwright(string $name, string $requirement, string $declaration = 'test'): string
    {
        return $declaration."('".$name."', { ".self::PLAYWRIGHT_TAG.": ['@".$requirement."'] }, async () => {});\n";
    }
}
