<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Construye codigo de prueba de mentira para los fixtures de las pruebas de
 * `qa:traceability`: la forma de cada etiqueta, en un solo sitio.
 *
 * Hasta la 2.3.1 esta clase ademas evitaba escribir la etiqueta de forma
 * literal, porque el escaner leia tests/ como texto. Ya no hace falta: el
 * escaner tokeniza PHP y lo que hay dentro de una cadena no cuenta
 * (V23-1-QA-1).
 *
 * Vive en tests/Support/ y no dentro de una suite a proposito: phpunit.xml solo
 * recoge Unit, Integration, Feature, Contract y Architecture, asi que este
 * fichero no se ejecuta como prueba.
 */
final class FakeTestSource
{
    private const string GROUP = 'group';

    private const string PLAYWRIGHT_TAG = 'tag';

    private const string K6_TAG = 'tags';

    private const string K6_REQUIREMENTS = 'requirements';

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

    /**
     * La misma llamada repartida en varias lineas y con el nombre tal cual se
     * escribe, delimitadores incluidos: una plantilla con acento grave —la de
     * las pruebas generadas en un `for`— o una cadena con comillas del otro
     * tipo dentro (V1-QA-1 de la 2.2.1).
     *
     *     test(
     *       `nombre (${variant.name})`,
     *       { tag: ['@RN-21'] },
     *       async () => {},
     *     )
     */
    public static function playwrightMultiline(string $nameLiteral, string $requirement): string
    {
        return "test(\n  ".$nameLiteral.",\n  { ".self::PLAYWRIGHT_TAG.": ['@".$requirement."'] },\n  async () => {},\n)\n";
    }

    /**
     * Un `export const options` de k6 con sus escenarios (tarea 3.6, dec. 11).
     *
     * El cuerpo de cada escenario va crudo para que las pruebas puedan montar
     * tambien lo que NO debe contar: una etiqueta fuera de `tags`, un `tags`
     * sin requisitos o un identificador mal escrito.
     *
     * @param  array<string, string>  $scenarios  Clave del escenario -> cuerpo.
     */
    public static function k6(array $scenarios): string
    {
        $blocks = '';

        foreach ($scenarios as $name => $body) {
            $blocks .= '    '.$name.": {\n"
                ."      executor: 'constant-arrival-rate',\n"
                .'      '.$body."\n"
                ."    },\n";
        }

        return "export const options = {\n  scenarios: {\n".$blocks."  },\n};\n";
    }

    /** `tags: { requirements: 'RNF-P-06 RNF-P-02' }`, la etiqueta nativa de k6. */
    public static function k6Requirements(string $value): string
    {
        return self::K6_TAG.': { '.self::K6_REQUIREMENTS.": '".$value."' },";
    }
}
