<?php

declare(strict_types=1);

use SebastianBergmann\FileIterator\Facade as FileIterator;

/*
 * La suite descubre TODOS los ficheros de prueba que hay en el arbol (RQ-13).
 *
 * POR QUE EXISTE. El 09-09-2026 se descubrio que, sobre el bind mount de Docker
 * Desktop (NTFS -> contenedor), `RecursiveDirectoryIterator` recorriendo
 * `tests/Feature` entero devolvia 78 ficheros de 116: 38 de los 41 de
 * `tests/Feature/Product` desaparecian sin ningun aviso. La suite local decia
 * «todo en verde» mientras unas 430 pruebas del modulo no se ejecutaban, y
 * `qa:traceability` contaba sus etiquetas como cobertura real. La CI en Linux
 * no lo sufria, asi que nadie lo vio.
 *
 * QUE COMPRUEBA. Lo que PHPUnit descubre a partir de `phpunit.xml` —cada
 * `<directory>` y cada `<file>` de cada suite, con el mismo iterador que usa el
 * ejecutor— frente a un `scandir` recursivo, que no sufre el fallo. Si falta un
 * fichero, esta prueba falla en voz alta y dice cual: la solucion conocida es
 * declarar el directorio afectado por separado en `phpunit.xml`, como ya se hace
 * con `tests/Feature`.
 *
 * Tambien detecta el olvido contrario: un directorio nuevo bajo `tests/Feature`
 * que nadie anadio a la lista de `phpunit.xml`.
 */

/**
 * @return list<string>
 */
function testFilesBySuiteConfiguration(string $root): array
{
    $xml = simplexml_load_file($root.'/phpunit.xml');
    expect($xml)->not->toBeFalse();
    assert($xml !== false);

    $iterator = new FileIterator;
    $found = [];

    foreach ($xml->testsuites->testsuite as $suite) {
        foreach ($suite->directory as $directory) {
            foreach ($iterator->getFilesAsArray($root.'/'.(string) $directory, 'Test.php') as $file) {
                $found[] = $file;
            }
        }

        foreach ($suite->file as $file) {
            $path = realpath($root.'/'.(string) $file);
            expect($path)->not->toBeFalse('phpunit.xml lista un fichero que no existe: '.(string) $file);
            assert(is_string($path));
            $found[] = $path;
        }
    }

    sort($found);

    return array_values(array_unique($found));
}

/**
 * @return list<string>
 */
function testFilesByScandir(string $directory): array
{
    $found = [];

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;

        if (is_dir($path)) {
            $found = [...$found, ...testFilesByScandir($path)];
        } elseif (str_ends_with($entry, 'Test.php')) {
            $real = realpath($path);
            assert(is_string($real));
            $found[] = $real;
        }
    }

    sort($found);

    return $found;
}

it('descubre con phpunit.xml exactamente los mismos ficheros de prueba que hay en el arbol', function (): void {
    $root = dirname(__DIR__, 2);

    $configured = testFilesBySuiteConfiguration($root);
    $onDisk = testFilesByScandir($root.'/tests');

    $missing = array_values(array_diff($onDisk, $configured));
    $extra = array_values(array_diff($configured, $onDisk));

    expect($missing)->toBe([], 'Ficheros de prueba que la configuracion NO descubre (bind mount de Docker Desktop o directorio sin declarar en phpunit.xml): '.implode(', ', array_map(static fn (string $f): string => substr($f, strlen($root) + 1), $missing)))
        ->and($extra)->toBe([]);
})->group('RQ-13');

it('lista en phpunit.xml cada subdirectorio de tests/Feature, porque la suite Feature va por partes', function (): void {
    $root = dirname(__DIR__, 2);
    $xml = simplexml_load_file($root.'/phpunit.xml');
    assert($xml !== false);

    $declared = [];

    foreach ($xml->testsuites->testsuite as $suite) {
        if ((string) $suite['name'] !== 'Feature') {
            continue;
        }

        foreach ($suite->directory as $directory) {
            $declared[] = basename((string) $directory);
        }
    }

    $onDisk = array_values(array_filter(
        scandir($root.'/tests/Feature') ?: [],
        static fn (string $entry): bool => ! in_array($entry, ['.', '..'], true) && is_dir($root.'/tests/Feature/'.$entry),
    ));

    sort($declared);
    sort($onDisk);

    expect($declared)->toBe($onDisk);
})->group('RQ-13');
