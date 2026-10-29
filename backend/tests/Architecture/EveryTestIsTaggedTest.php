<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\Repo;
use Tests\Architecture\Support\UntaggedTests;

/*
 * Toda prueba declara que requisito cubre (RQ-13; V21-1-QA-1 de la 2.2.1,
 * tarea 22.2 de la 2.3.0).
 *
 * `qa:traceability --check` falla si un requisito no tiene prueba, pero no si
 * una prueba no tiene requisito: diecisiete vivieron asi —las doce de
 * `DeployedVersionTest`, tres de las sondas y dos del marco del panel— sin
 * figurar en la matriz que se entrega como evidencia de lo que esta probado.
 * Esta es la puerta que lo exige, sobre Pest (todas las suites) y Playwright
 * (las tres SPA). Como se reconoce una prueba y una etiqueta lo explica
 * {@see UntaggedTests}.
 *
 * Una prueba cuyo requisito no existe en el catalogo no se arregla quitandole
 * la etiqueta: se etiqueta con el requisito de lo que recorre (como las pasadas
 * de axe del panel) o se discute en revision que requisito falta.
 */

/**
 * Las pruebas sin etiqueta de un arbol, como «fichero:linea nombre».
 *
 * @param  list<string>  $files
 * @param  callable(string): list<array{line: int, name: string}>  $reader
 * @return list<string>
 */
function everyTestIsTaggedOffenders(array $files, string $root, callable $reader): array
{
    $offenders = [];

    foreach ($files as $file) {
        foreach ($reader((string) file_get_contents($file)) as $test) {
            $offenders[] = ModuleTree::relative($file, $root).':'.$test['line'].' '.$test['name'];
        }
    }

    return $offenders;
}

/** @return list<string> */
function everyTestIsTaggedPestFiles(): array
{
    return array_values(array_filter(
        ModuleTree::phpFilesUnder(dirname(__DIR__)),
        // Los ayudantes de `tests/Support` y `tests/Architecture/Support` no
        // terminan en `Test.php`; `tests/Unit/Support` si son pruebas.
        static fn (string $file): bool => str_ends_with($file, 'Test.php'),
    ));
}

/** @return list<string> */
function everyTestIsTaggedPlaywrightFiles(): array
{
    $files = [];

    foreach (['frontend-kiosk', 'frontend-admin', 'frontend-portal'] as $app) {
        $finder = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(Repo::file($app.'/tests/e2e'), FilesystemIterator::SKIP_DOTS));

        foreach ($finder as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.spec.ts')) {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

it('etiqueta con un requisito toda prueba de Pest', function (): void {
    expect(everyTestIsTaggedOffenders(everyTestIsTaggedPestFiles(), dirname(__DIR__), UntaggedTests::pest(...)))->toBe(
        [],
        'Prueba(s) de Pest sin ->group() de requisito: anade el RF/RN/RQ/RNF que cubre (doc 02 §9.6).',
    );
})->group('RQ-13');

it('etiqueta con un requisito toda prueba de Playwright', function (): void {
    expect(everyTestIsTaggedOffenders(everyTestIsTaggedPlaywrightFiles(), Repo::root(), UntaggedTests::playwright(...)))->toBe(
        [],
        "Prueba(s) de Playwright sin { tag: ['@…'] } de requisito: anade el RF/RN/RQ/RNF que cubre (doc 02 §9.6).",
    );
})->group('RQ-13');

it('lee el arbol entero y no pasa en verde por no ver nada', function (): void {
    // Con cero ficheros las dos de arriba saldrian en verde sin mirar nada.
    expect(count(everyTestIsTaggedPestFiles()))->toBeGreaterThan(800)
        ->and(count(everyTestIsTaggedPlaywrightFiles()))->toBeGreaterThan(70);
})->group('RQ-13');

it('reconoce una prueba de Pest sin etiqueta y no la confunde con una que la lleva', function (string $source, array $untagged): void {
    expect(array_column(UntaggedTests::pest("<?php\n".$source), 'name'))->toBe($untagged);
})->with([
    'con etiqueta' => ["it('a', function () {})->group('RN-05');", []],
    'sin etiqueta' => ["it('a', function () {});", ['a']],
    'con dataset y etiqueta' => ["it('a', function (int \$x) {})->with([1, 2])->group('RF-AT-01', 'performance');", []],
    'con dataset y sin etiqueta' => ["it('a', function (int \$x) {})->with([1, 2]);", ['a']],
    'con un grupo que no es un requisito' => ["it('a', function () {})->group('performance');", ['a']],
    'con un group dentro del cuerpo, que no cuenta' => ["it('a', function () { \$x->group('RN-05'); });", ['a']],
    'test en vez de it' => ["test('a', function () {});", ['a']],
    'una prueba dentro de una cadena' => ["\$fuente = \"it('a', function () {});\";", []],
    'un metodo que se llama it' => ["\$this->it('a');", []],
    'una funcion que se llama test' => ['function test(string $a): void {}', []],
    'dos pruebas, la segunda sin etiqueta' => ["it('a', function () {})->group('RQ-13');\nit('b', function () {});", ['b']],
    'con un atributo en una clase anonima del cuerpo' => ["it('a', function () { new class { #[Override] public function f(): void {} }; })->group('RN-05');", []],
])->group('RQ-13');

/*
 * La etiqueta se monta al ejecutar y no va escrita en la fuente:
 * `qa:traceability` lee este fichero como texto, y la errata escrita dentro
 * de un group le pareceria una etiqueta mal escrita de esta prueba, que hoy
 * bloquea.
 */
it('no da por etiquetada la prueba de Pest cuyo unico requisito no es un requisito del catalogo', function (string $etiqueta): void {
    $fuente = "<?php\nit('a', function () {})->group('".$etiqueta."');";

    expect(array_column(UntaggedTests::pest($fuente), 'name'))->toBe(['a']);
})->with([
    'una errata: la letra O en vez del cero' => ['RN-O5'],
    'un prefijo que no existe' => ['RX-99'],
    'con forma de requisito, pero fuera del catalogo' => ['RN-99'],
    'con forma de rango, que una etiqueta no puede ser' => ['RF-ID-04..09'],
])->group('RQ-13');

it('da por etiquetada la prueba de Pest que cita un requisito del catalogo junto a otros grupos', function (): void {
    $requisito = 'RN-05';
    $fuente = "<?php\nit('a', function () {})->group('performance', '".$requisito."');";

    expect(UntaggedTests::pest($fuente))->toBe([]);
})->group('RQ-13');

it('no da por etiquetada la prueba de Playwright cuyo unico requisito no es un requisito del catalogo', function (string $etiqueta): void {
    $fuente = "test('a', { tag: ['@".$etiqueta."'] }, async () => {})";

    expect(array_column(UntaggedTests::playwright($fuente), 'name'))->toBe(['a']);
})->with([
    'una errata: la letra O en vez del cero' => ['RN-O5'],
    'un prefijo que no existe' => ['RX-99'],
    'con forma de requisito, pero fuera del catalogo' => ['RN-99'],
])->group('RQ-13');

it('reconoce una prueba de Playwright sin etiqueta y no la confunde con una que la lleva', function (string $source, array $untagged): void {
    expect(array_column(UntaggedTests::playwright($source), 'name'))->toBe($untagged);
})->with([
    'con etiqueta' => ["test('a', { tag: ['@RF-KI-01'] }, async () => {})", []],
    'sin etiqueta' => ["test('a', async () => {})", ['a']],
    'con una etiqueta que no es un requisito' => ["test('a', { tag: ['@regla-dura-18'] }, async () => {})", ['a']],
    'con la lista de su pantalla' => ['test(`la ruta ${name}`, { tag: [...pass.requirements] }, async () => {})', []],
    'un describe no es una prueba' => ["test.describe('grupo', () => {})", []],
    'un paso no es una prueba' => ["await test.step('paso', async () => {})", []],
    'una prueba citada en un comentario' => ["// van literales en cada `test()` del fichero\ntest('a', { tag: ['@RF-KI-01'] }, async () => {})", []],
    'con el nombre partido en dos cadenas' => ["test(\n  'a ' +\n    'b',\n  { tag: ['@RF-IN-06'] },\n  async () => {},\n)", []],
])->group('RQ-13');
