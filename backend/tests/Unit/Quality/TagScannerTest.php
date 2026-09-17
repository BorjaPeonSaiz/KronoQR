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

/**
 * Borra el arbol temporal entero, subdirectorios incluidos: los fixtures de k6
 * llevan un `.results/` que un `glob()` de un solo nivel deja atras.
 */
function removeScannerFixture(string $directory): void
{
    // Con `scandir` y no con `glob`: las entradas ocultas necesitarian
    // `GLOB_BRACE`, que la musl de la imagen Alpine no trae.
    foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
        $path = $directory.'/'.$entry;

        is_dir($path) ? removeScannerFixture($path) : @unlink($path);
    }

    @rmdir($directory);
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/kronoqr-scan-*') ?: [] as $directory) {
        removeScannerFixture($directory);
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

/*
 * Las formas del salto, que NO son todas la misma cosa.
 *
 * `->skip('motivo')` no se ejecuta nunca y por tanto no cubre nada. `->skip(!
 * hayChromium(), 'motivo')` se ejecuta en cuanto Chromium esta, que es el
 * patron de las nueve pruebas del arbol que dependen de una herramienta que no
 * hay en todos los entornos (`FpmPoolRenderTest`, `PeriodReportPdfSealTest`,
 * `InstructionsSheetLayoutTest`). Tratarlas como saltadas dejaba a RNF-P-06,
 * RF-IN-04, RL-05 y RF-QR-06 sin cobertura y las sacaba en los avisos con el
 * rotulo de «etiqueta con forma de requisito que no lo es», que ademas es
 * falso.
 *
 * El criterio es el primer argumento: cadena literal (o ninguno) -> el salto es
 * siempre; cualquier otra cosa -> es una expresion y puede no cumplirse.
 */
dataset('formas del salto de Pest', [
    'sin argumentos' => ['->skip()', false],
    'con motivo' => ["->skip('pendiente de la 2.4')", false],
    'con motivo entre comillas dobles' => ['->skip("pendiente de la 2.4")', false],
    'pendiente de escribir' => ['->todo()', false],
    'condicionado a una funcion con parentesis dentro' => ["->skip(! hayChromium(), 'no esta instalado')", true],
    'condicionado sin motivo' => ["->skip(PHP_OS_FAMILY !== 'Linux')", true],
    'condicionado a una constante' => ['->skip(self::SIN_FPM, "php-fpm no vive aqui")', true],
]);

it('distingue el salto incondicional del condicionado al entorno', function (string $chained, bool $cubre): void {
    $root = scannerFixture('SkipFormsTest.php', FakeTestSource::file([
        FakeTestSource::pest('una prueba', ['RL-04'], chained: $chained),
    ]));

    $scan = scanOf($root);

    expect(array_keys($scan->byRequirement()))->toBe($cubre ? ['RL-04'] : []);

    $cubre
        ? expect($scan->malformed)->toBe([])
        : expect(implode(' ', $scan->malformed))->toContain('esta saltada y no cubre RL-04');
})->with('formas del salto de Pest')->group('RQ-13');

it('marca como condicionada la prueba que solo corre si su herramienta esta', function (): void {
    // La matriz las enumera aparte: cuentan como cobertura, pero su verde
    // depende de la maquina que las ejecute y eso se escribe donde se lee.
    $root = scannerFixture('ConditionalTest.php', FakeTestSource::file([
        FakeTestSource::pest('sella el PDF', ['RF-IN-04'], chained: "->skip(! hayChromium(), 'no esta instalado')"),
        FakeTestSource::pest('no depende de nada', ['RN-05']),
    ]));

    $tests = scanOf($root)->byRequirement();

    expect($tests['RF-IN-04'][0]->conditional)->toBeTrue();
    expect($tests['RN-05'][0]->conditional)->toBeFalse();
})->group('RQ-13');

it('lee el salto condicionado tambien cuando va por delante de la etiqueta', function (): void {
    $root = scannerFixture('ConditionalFirstTest.php', FakeTestSource::file([
        FakeTestSource::pest('sella el PDF', ['RF-IN-04'], before: "->skip(! hayChromium(), 'no esta instalado')"),
    ]));

    expect(array_keys(scanOf($root)->byRequirement()))->toBe(['RF-IN-04']);
})->group('RQ-13');

it('no cuenta la prueba que Playwright declara saltada por su nombre', function (): void {
    $root = scannerFixture('saltada.spec.ts', FakeTestSource::playwright('ficha', 'RF-KI-03', 'test.skip'));

    expect(scanOf($root, 'playwright')->byRequirement())->toBe([]);
})->group('RQ-13');

it('no deja que un test.skip condicional se lleve las etiquetas de la prueba siguiente', function (): void {
    // `test.skip(cond, 'motivo')` es una sentencia suelta, no el salto de la
    // prueba que viene detras. Si se la llevara, la E2E de al lado perderia su
    // cobertura sin que nadie tocara su etiqueta.
    $root = scannerFixture('condicional.spec.ts',
        "test.skip(browserName === 'firefox', 'sin camara simulada');\n"
        .FakeTestSource::playwright('ficha con la tarjeta', 'RF-KI-03'));

    expect(array_keys(scanOf($root, 'playwright')->byRequirement()))->toBe(['RF-KI-03']);
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

/*
 * k6, el tercer formato del §9.6 (tarea 3.6, decision 11).
 *
 * La prueba de carga es la UNICA cobertura automatica de RNF-P-06 y RQ-08: no
 * se ejecuta en cada cambio —dura minutos y necesita la pila levantada— asi que
 * su etiqueta se lee del fuente, como la de Playwright. El criterio que fijan
 * estas pruebas:
 *
 *   - Cuenta lo que k6 ve de verdad: `tags: { requirements: '...' }` dentro del
 *     escenario. Es etiqueta nativa, la misma que viaja en cada muestra del CSV
 *     y por la que agrupa el agregado, asi que la matriz y la medicion hablan
 *     de lo mismo. Un `requirements:` fuera de `tags` no es una etiqueta de k6
 *     y por tanto no cubre nada.
 *   - El nombre es la clave del escenario, sacada de la estructura del objeto
 *     y no de la linea anterior: la etiqueta suele ir al final del escenario.
 *   - El separador acordado es el espacio, que es lo que k6 admite como valor;
 *     se toleran la coma y la arroba, pero no las erratas.
 */

it('encuentra la etiqueta de un escenario de k6 con un requisito y con varios', function (): void {
    $root = scannerFixture('scan-peak.js', FakeTestSource::k6([
        'scan' => FakeTestSource::k6Requirements('RNF-P-06 RNF-P-02 RQ-08'),
        'reject' => FakeTestSource::k6Requirements('RS-03'),
    ]));

    expect(array_keys(scanOf($root, 'k6')->byRequirement()))
        ->toBe(['RNF-P-02', 'RNF-P-06', 'RQ-08', 'RS-03']);
})->group('RQ-13');

it('atribuye cada etiqueta de k6 a la clave de su escenario y a su linea', function (): void {
    // Lo que se lee en la matriz es «load-tests/k6/scan-peak.js:NN — resend».
    // Si el nombre se tomara de la linea anterior seria `executor` o el ultimo
    // escenario cerrado, y la matriz señalaria al escenario equivocado.
    $root = scannerFixture('scan-peak.js', FakeTestSource::k6([
        'scan' => FakeTestSource::k6Requirements('RNF-P-06'),
        'resend' => FakeTestSource::k6Requirements('RQ-03'),
    ]));

    $test = scanOf($root, 'k6')->byRequirement()['RQ-03'][0];

    expect($test->name)->toBe('resend');
    expect($test->tool)->toBe('k6');
    expect($test->reference())->toBe('scan-peak.js:9');
})->group('RQ-13');

it('tolera la coma y la arroba como separador en la etiqueta de k6', function (): void {
    // Quien escribe el escenario viene de etiquetar pruebas de Playwright.
    $root = scannerFixture('scan-peak.js', FakeTestSource::k6([
        'batch' => FakeTestSource::k6Requirements('@RF-KI-04, RQ-03'),
    ]));

    $scan = scanOf($root, 'k6');

    expect(array_keys($scan->byRequirement()))->toBe(['RF-KI-04', 'RQ-03']);
    expect($scan->malformed)->toBe([]);
})->group('RQ-13');

it('aparta la etiqueta de k6 con forma de requisito que no lo es', function (): void {
    $root = scannerFixture('scan-peak.js', FakeTestSource::k6([
        'scan' => FakeTestSource::k6Requirements('RNF-P-06 RN-O5'),
    ]));

    $scan = scanOf($root, 'k6');

    expect(array_keys($scan->byRequirement()))->toBe(['RNF-P-06']);
    expect(implode(' ', $scan->malformed))->toContain('RN-O5');
})->group('RQ-13');

it('NO cuenta un requirements que no esta dentro de tags', function (): void {
    // No es una etiqueta de k6: no viaja en las muestras, el agregado no agrupa
    // por ella y el ejecutor la ignora. Darla por buena haria figurar RNF-P-06
    // como medido por un escenario que no lo mide.
    $root = scannerFixture('scan-peak.js', FakeTestSource::k6([
        'scan' => "requirements: 'RNF-P-06',",
    ]));

    expect(scanOf($root, 'k6')->byRequirement())->toBe([]);
})->group('RQ-13');

it('no cuenta una etiqueta de k6 comentada ni se pierde con las llaves de una cadena', function (): void {
    // Las llaves de cadenas y comentarios se ciegan antes de buscar: una
    // etiqueta comentada deja de contar —el requisito se queda sin cobertura y
    // `--check` bloquea, que es el lado correcto por el que fallar— y un `{`
    // dentro de una cadena no desplaza el nombre del escenario.
    $root = scannerFixture('scan-peak.js', FakeTestSource::k6([
        'scan' => "env: { PAYLOAD: 'FH1.{k}' },\n      "
            .'// '.FakeTestSource::k6Requirements('RNF-P-06')."\n      "
            .FakeTestSource::k6Requirements('RQ-08'),
    ]));

    $tests = scanOf($root, 'k6')->byRequirement();

    expect(array_keys($tests))->toBe(['RQ-08']);
    expect($tests['RQ-08'][0]->name)->toBe('scan');
})->group('RQ-13');

it('dice «(fichero)» cuando ninguna llave por encima tiene nombre', function (): void {
    // Un objeto dentro de una lista no tiene clave, y la de `options` tampoco
    // es una clave: es una asignacion. Inventarse un nombre seria peor que no
    // darlo, porque la matriz señalaria a un escenario que no existe.
    $root = scannerFixture('array-peak.js',
        "export const options = {\n  scenarios: [\n    { ".FakeTestSource::k6Requirements('RS-03')." },\n  ],\n};\n");

    $tests = scanOf($root, 'k6')->byRequirement();

    expect($tests['RS-03'][0]->name)->toBe('(fichero)');
})->group('RQ-13');

it('no se trae una clave que esta demasiado lejos de su llave', function (): void {
    // La ventana por delante de la llave es corta a proposito: entre una clave
    // y su `{` solo cabe espacio en blanco. Mirando mas atras, el nombre del
    // escenario acabaria siendo el de cualquier cosa anterior del fichero. Con
    // la clave fuera de la ventana, la llave queda anonima y se sigue hacia
    // fuera hasta la primera con nombre, que es `scenarios`.
    $root = scannerFixture('lejos-peak.js',
        "export const options = {\n  scenarios: {\n    scan:".str_repeat("\n", 130)
        .'{ '.FakeTestSource::k6Requirements('RS-03')." },\n  },\n};\n");

    $tests = scanOf($root, 'k6')->byRequirement();

    expect($tests['RS-03'][0]->name)->toBe('scenarios');
})->group('RQ-13');

it('no explora los ficheros de k6 que no son un escenario', function (): void {
    // `aggregate.js` es el agregado de resultados —habla de `scenarios` porque
    // los informa— y `aggregate.test.js` son sus pruebas de Node. Ninguno de
    // los dos es algo que k6 pueda ejecutar.
    $root = scannerFixture('scan-peak.js', FakeTestSource::k6([
        'scan' => FakeTestSource::k6Requirements('RNF-P-06'),
    ]));
    file_put_contents($root.'/aggregate.js',
        "const report = { scenarios: {} }\nconst etiqueta = { ".FakeTestSource::k6Requirements('RS-03')." }\n");
    file_put_contents($root.'/aggregate.test.js', FakeTestSource::k6([
        'copia' => FakeTestSource::k6Requirements('RQ-03'),
    ]));

    $scan = scanOf($root, 'k6');

    expect(array_keys($scan->byRequirement()))->toBe(['RNF-P-06']);
    expect($scan->scannedFiles['k6'])->toBe(1);
})->group('RQ-13');

it('no busca etiquetas de k6 en la salida de la prueba de carga', function (): void {
    // `.results/` son los CSV y los registros de cada instancia, no fuente.
    $root = scannerFixture('scan-peak.js', FakeTestSource::k6([
        'scan' => FakeTestSource::k6Requirements('RNF-P-06'),
    ]));
    mkdir($root.'/.results');
    file_put_contents($root.'/.results/instance-1.js', FakeTestSource::k6([
        'copia' => FakeTestSource::k6Requirements('RS-03'),
    ]));

    $scan = scanOf($root, 'k6');

    expect(array_keys($scan->byRequirement()))->toBe(['RNF-P-06']);
    expect($scan->scannedFiles['k6'])->toBe(1);
})->group('RQ-13');
