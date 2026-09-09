<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\Repo;

/*
 * El arbol de `app/` se recorre ENTERO, y esta prueba es la que lo dice
 * (RQ-13, RQ-14).
 *
 * POR QUE EXISTE. Es el gemelo de `TestDiscoveryTest` para el codigo de
 * produccion, y nacio del mismo hallazgo. Sobre el *bind mount* de Docker
 * Desktop (NTFS -> contenedor), `RecursiveDirectoryIterator` devuelve menos
 * ficheros de los que hay, sin lanzar, sin avisar y sin dejar rastro. En
 * `tests/Feature` se dejaba 38 de 116 y unas 430 pruebas no se ejecutaban. En
 * `app/` se deja los 45 de `Product/Domain/ValueObject`: 1.189 de 1.234.
 *
 * POR QUE ES PEOR AQUI QUE EN LAS PRUEBAS. Una prueba que no se descubre al
 * menos no cuenta como ejecutada. Un fichero de produccion que un recorrido no
 * ve produce **un verde falso**: `OutboundChannelsTest` afirmaba que ningun
 * fichero abre una conexion saliente habiendo mirado 1.189 de 1.234;
 * `DataProtectionGuaranteesTest` afirmaba lo mismo de los servicios de terceros;
 * la mutacion y la cobertura locales de `Product` median 52 de 97 ficheros y
 * daban un MSI que no era el del modulo. Ninguna de esas cifras decia nada.
 *
 * QUE COMPRUEBA. Un `scandir` recursivo —que no sufre el fallo— contra el mismo
 * `RecursiveDirectoryIterator` que usan PHPUnit, Infection, Deptrac y cualquier
 * herramienta que recorra el arbol. Si difieren, el entorno esta perdiendo
 * ficheros y **cualquier medida tomada sobre `app/` en esta maquina es
 * incompleta**, empezando por las de esta misma suite.
 *
 * QUE HACER SI FALLA. No es un defecto del producto: es el sistema de ficheros.
 * Las pruebas de arquitectura ya recorren con `scandir`
 * ({@see ModuleTree::phpFilesUnder()}) y no se ven afectadas. Lo que si queda
 * afectado es todo lo que recorre por su cuenta —cobertura, mutacion,
 * Deptrac—: esas cifras se toman de la CI, que corre sobre Linux sin bind
 * mount, nunca de local.
 */

/**
 * Los `.php` bajo una ruta, vistos por `RecursiveDirectoryIterator`.
 *
 * Es la ESCRITURA PROHIBIDA en el resto de la suite, y aqui se usa a proposito:
 * esta prueba existe para medir cuanto pierde.
 *
 * @return list<string>
 */
function phpFilesByIterator(string $directory): array
{
    $files = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    ) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

it('ve los mismos ficheros de app/ recorriendo con scandir que con el iterador recursivo', function (): void {
    $app = Repo::file('backend/app');

    $conScandir = ModuleTree::phpFilesUnder($app);
    $conIterador = phpFilesByIterator($app);

    $perdidos = array_values(array_diff($conScandir, $conIterador));

    expect($perdidos)->toBe(
        [],
        'El sistema de ficheros esta perdiendo '.\count($perdidos).' de '.\count($conScandir)
        .' ficheros de app/ al recorrerlos con RecursiveDirectoryIterator: '
        .implode(', ', array_map(static fn (string $f): string => substr($f, \strlen($app) + 1), $perdidos))
        .'. Es el bind mount de Docker Desktop (ver el docblock de este fichero). Las pruebas de '
        .'arquitectura ya recorren con scandir y siguen siendo validas; la cobertura, la mutacion y '
        .'Deptrac de ESTA maquina NO lo son, y hay que leerlas de la CI.'
    );
})->group('RQ-13', 'RQ-14');

it('no encuentra con el iterador ningun fichero que scandir no vea', function (): void {
    // La direccion contraria, que seria un fallo de `phpFilesUnder()` y no del
    // entorno: si `scandir` se dejara algo, la suite de arquitectura entera
    // estaria mirando menos codigo del que cree, y esta vez sin excusa.
    $app = Repo::file('backend/app');

    $sobrantes = array_values(array_diff(phpFilesByIterator($app), ModuleTree::phpFilesUnder($app)));

    expect($sobrantes)->toBe([], 'ModuleTree::phpFilesUnder() se deja ficheros que el iterador si ve: '
        .implode(', ', $sobrantes));
})->group('RQ-13', 'RQ-14');
