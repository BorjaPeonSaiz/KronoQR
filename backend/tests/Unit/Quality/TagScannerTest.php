<?php

declare(strict_types=1);

use App\Console\Commands\Quality\Support\TagScan;
use App\Console\Commands\Quality\Support\TagScanner;
use Tests\Support\FakeTestSource;

/*
 * El extractor de etiquetas: la mitad de la trazabilidad que dice QUE esta
 * cubierto.
 *
 * Lee el codigo fuente en vez de arrancar Pest y Playwright, y esa decision
 * tiene una contrapartida declarada: lo que no encaja no se descarta, se
 * devuelve en `malformed` para que el comando lo enseñe. Estas pruebas fijan
 * ese contrato, porque un extractor que se traga lo que no entiende convierte
 * la matriz de trazabilidad en un recuento de etiquetas.
 *
 * Los fixtures se construyen con FakeTestSource y no se escriben a mano: el
 * escaner lee tests/ como texto, asi que un `->group('RN-05')` literal en este
 * fichero haria figurar RN-05 como cubierto por una prueba inexistente.
 */

/** Crea un arbol temporal con un fichero de prueba dentro y devuelve su raiz. */
function scannerFixture(string $name, string $source): string
{
    $root = sys_get_temp_dir().'/kronoqr-scan-'.bin2hex(random_bytes(6));
    mkdir($root, 0o777, true);
    file_put_contents($root.'/'.$name, $source);

    return $root;
}

function scanOf(string $root, string $tool = 'pest'): TagScan
{
    return (new TagScanner([$tool => [$root]], $root))->scan();
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/kronoqr-scan-*') ?: [] as $directory) {
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }
});

it('encuentra las etiquetas de Pest, con uno y con varios requisitos', function (): void {
    $root = scannerFixture('ExampleTest.php', FakeTestSource::file([
        FakeTestSource::pest('uno', ['RN-05']),
        FakeTestSource::pest('dos', ['RF-AT-08', 'RL-04']),
    ]));

    expect(array_keys(scanOf($root)->byRequirement()))->toBe(['RF-AT-08', 'RL-04', 'RN-05']);
})->group('RQ-13');

it('lee tambien el atributo #[Group] de PHPUnit', function (): void {
    $root = scannerFixture('AttributeTest.php', FakeTestSource::attribute('RN-06'));

    expect(array_keys(scanOf($root)->byRequirement()))->toBe(['RN-06']);
})->group('RQ-13');

it('lee las etiquetas de Playwright', function (): void {
    $root = scannerFixture('flujo.spec.ts', FakeTestSource::playwright('ficha', 'RF-KI-03'));

    expect(array_keys(scanOf($root, 'playwright')->byRequirement()))->toBe(['RF-KI-03']);
})->group('RQ-13');

it('NO cuenta como cubierto un requisito cuya prueba esta saltada', function (): void {
    // El hueco que encontro el cierre de la Fase 0. Un salto hacia figurar
    // RL-04 como cubierto en la matriz —que se entrega como evidencia de que
    // cada obligacion legal tiene prueba automatica— sin verificar nada.
    $root = scannerFixture('SkippedTest.php', FakeTestSource::file([
        FakeTestSource::pest('conserva el registro', ['RL-04'], chained: "->skip('pendiente de la 2.4')"),
    ]));

    $scan = scanOf($root);

    expect($scan->byRequirement())->toBe([]);
    expect(implode(' ', $scan->malformed))->toContain('esta saltada y no cubre RL-04');
})->group('RQ-13');

it('detecta el salto encadenado por delante de la etiqueta', function (): void {
    // Las dos formas son la misma prueba saltada.
    $root = scannerFixture('SkippedFirstTest.php', FakeTestSource::file([
        FakeTestSource::pest('algo', ['RL-04'], before: "->skip('luego')"),
    ]));

    expect(scanOf($root)->byRequirement())->toBe([]);
})->group('RQ-13');

it('detecta ->todo() como prueba que no cubre', function (): void {
    $root = scannerFixture('TodoTest.php', FakeTestSource::file([
        FakeTestSource::pest('pendiente de escribir', ['RS-08'], chained: '->todo()'),
    ]));

    expect(scanOf($root)->byRequirement())->toBe([]);
})->group('RQ-13');

it('no confunde con un salto una prueba que solo HABLA de saltos', function (): void {
    // Regresion. La primera version miraba la sentencia entera, desde la
    // declaracion hasta el `;`, asi que el cuerpo entraba en el vano: cualquier
    // prueba que mencionara un salto perdia sus propias etiquetas. Se noto
    // porque las pruebas de este mismo fichero dejaron de cubrir RQ-13.
    $root = scannerFixture('MentionsSkipTest.php', "<?php\n"
        ."it('explica el salto', function (): void {\n"
        ."    \$fixture = \"it('x')->skip('pendiente')\";\n"
        ."    expect(\$fixture)->toBeString();\n"
        .'})'.'->'."group('RN-05');\n");

    expect(array_keys(scanOf($root)->byRequirement()))->toBe(['RN-05']);
})->group('RQ-13');

it('sigue contando las pruebas que si se ejecutan del mismo fichero', function (): void {
    // Que una prueba este saltada no puede arrastrar a sus vecinas.
    $root = scannerFixture('MixedTest.php', FakeTestSource::file([
        FakeTestSource::pest('esta corre', ['RN-05']),
        FakeTestSource::pest('esta no', ['RL-04'], chained: "->skip('pendiente')"),
        FakeTestSource::pest('esta tambien corre', ['RN-06']),
    ]));

    expect(array_keys(scanOf($root)->byRequirement()))->toBe(['RN-05', 'RN-06']);
})->group('RQ-13');

it('aparta las etiquetas con forma de requisito que no lo son', function (): void {
    // `RN-O5` con la letra O es la clase de errata por la que un requisito se
    // queda sin prueba mientras alguien cree haberla escrito.
    $root = scannerFixture('TypoTest.php', FakeTestSource::file([
        FakeTestSource::pest('uno', ['RN-O5']),
    ]));

    $scan = scanOf($root);

    expect($scan->byRequirement())->toBe([]);
    expect(implode(' ', $scan->malformed))->toContain('RN-O5');
})->group('RQ-13');

it('no confunde un grupo normal con un requisito', function (): void {
    $root = scannerFixture('GroupsTest.php', FakeTestSource::file([
        FakeTestSource::pest('uno', ['slow', 'smoke', 'RN-05']),
    ]));

    $scan = scanOf($root);

    expect(array_keys($scan->byRequirement()))->toBe(['RN-05']);
    expect($scan->malformed)->toBe([]);
})->group('RQ-13');

it('declara los directorios que no ha podido mirar en vez de dar cero pruebas', function (): void {
    // Un extractor que devuelve «0 pruebas» sin decir si es que no hay ninguna
    // o que no ha podido verlas da una garantia que no presta.
    $scan = (new TagScanner(['playwright' => ['/tmp/no/existe/e2e']], '/tmp'))->scan();

    expect($scan->tests)->toBe([]);
    expect($scan->missingRoots['playwright'])->toBe(['no/existe/e2e']);
})->group('RQ-13');

it('atribuye cada etiqueta al nombre de su prueba', function (): void {
    $root = scannerFixture('NamedTest.php', FakeTestSource::file([
        FakeTestSource::pest('no parte el turno a medianoche', ['RN-05']),
    ]));

    $tests = scanOf($root)->byRequirement()['RN-05'];

    expect($tests[0]->name)->toBe('no parte el turno a medianoche');
})->group('RQ-13');
