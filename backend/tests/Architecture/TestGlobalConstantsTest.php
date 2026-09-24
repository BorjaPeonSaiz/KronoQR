<?php

declare(strict_types=1);

use Tests\Architecture\Support\GlobalTestConstants;
use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\Repo;

/*
 * Dos ficheros de prueba no pueden declarar la misma constante global.
 *
 * DE DONDE SALE. Del cierre de la Fase 3. `const AHORA_DEL_FICHAJE` estaba
 * declarada en dos ficheros de la misma suite, y una constante a nivel de
 * fichero no tiene espacio de nombres que la separe: es la misma. PHP emite el
 * aviso de redeclaracion **mientras Pest carga los ficheros**, antes de imprimir
 * una sola linea de resultados; el proceso sale con codigo 1 y la salida no
 * nombra ni la constante, ni el fichero, ni la prueba. La suite en rojo sin
 * decir por que es el peor fallo que puede tener una bateria de pruebas: no se
 * distingue de un fallo real y se busca donde no esta.
 *
 * LA RECETA, y es la parte util del mensaje cuando esto falle: **la constante
 * lleva el prefijo del fichero que la declara.** `AHORA_DEL_FICHAJE` en
 * `ScanIdempotencyTest.php` se llama `SCAN_IDEMPOTENCY_AHORA_DEL_FICHAJE`. Lo
 * que se gana no es solo evitar la colision: en la salida de un fallo, el
 * prefijo dice de que fichero sale el valor.
 *
 * ESTO NO ES LA REGLA DEL IDIOMA. En `tests/` el espanol esta bien —y
 * `AHORA_DEL_FICHAJE` esta bien escrito—: lo que se exige aqui es el prefijo.
 * La regla del idioma se aplica a `app/` y la comprueba
 * `IdentifierLanguageTest`.
 */

it('no declara la misma constante global en dos ficheros de prueba', function (): void {
    $tests = Repo::file('backend/tests');

    $duplicates = GlobalTestConstants::duplicatesUnder($tests);

    expect($duplicates)->toBe([], 'Hay '.\count($duplicates).' constantes globales declaradas en mas de un '
        .'fichero de prueba. No hay espacio de nombres que las separe: PHP avisa de la redeclaracion '
        ."mientras Pest carga los ficheros, la suite sale con codigo 1 y no imprime nada que lo explique.\n"
        .'Se arregla poniendole a cada una el prefijo de su fichero (AHORA_DEL_FICHAJE en '
        ."ScanIdempotencyTest.php -> SCAN_IDEMPOTENCY_AHORA_DEL_FICHAJE):\n- "
        .implode("\n- ", array_map(
            static fn (string $name, array $files): string => $name.' en '.implode(' y en ', $files),
            array_keys($duplicates),
            $duplicates,
        )));
})->group('RNF-M-06');

it('ha recorrido el arbol entero de tests/ y ha visto sus constantes globales', function (): void {
    // Sin esto, la prueba de arriba pasa en verde recorriendo un directorio
    // vacio, que es la forma de fallo que no se ve. Hoy hay 178 constantes
    // globales en 527 ficheros; el suelo de 100 no mide nada, solo detecta que
    // el recorrido se ha roto.
    $tests = Repo::file('backend/tests');

    $files = ModuleTree::phpFilesUnder($tests);
    $constants = array_merge(...array_map(GlobalTestConstants::declaredIn(...), $files));

    expect(\count($files))->toBeGreaterThan(300);
    expect(\count($constants))->toBeGreaterThan(100);
})->group('RNF-M-06');

it('reconoce la constante declarada a nivel de fichero', function (): void {
    $source = <<<'PHP'
        <?php

        const SCAN_IDEMPOTENCY_AHORA_DEL_FICHAJE = '2026-03-29T01:30:00Z';
        PHP;

    expect(GlobalTestConstants::declaredInSource($source))
        ->toBe([['name' => 'SCAN_IDEMPOTENCY_AHORA_DEL_FICHAJE', 'line' => 3]]);
})->group('RNF-M-06');

it('no confunde una constante de clase con una global', function (): void {
    // Una constante de clase vive dentro de un tipo y de un espacio de nombres:
    // dos con el mismo nombre no chocan, y denunciarlas seria ruido. Por eso la
    // deteccion va por profundidad de llaves y no por `grep const`.
    $source = <<<'PHP'
        <?php

        final class TwoClocks
        {
            public const string AHORA_DEL_FICHAJE = '2026-03-29T01:30:00Z';
        }
        PHP;

    expect(GlobalTestConstants::declaredInSource($source))->toBe([]);
})->group('RNF-M-06');

it('no se deja enganar por una llave dentro de una cadena', function (): void {
    // La interpolacion abre un `{` que ninguna llave de codigo cierra, y una
    // cuenta ingenua dejaria la profundidad descuadrada para todo lo que venga
    // detras: la constante global de la ultima linea se dejaria de ver y el
    // duplicado pasaria en verde.
    $source = <<<'PHP'
        <?php

        $etiqueta = "turno {$empleado} de {$centro}";

        const OUT_OF_ORDER_TARJETA = 'FH1.k1.token.sig';
        PHP;

    expect(GlobalTestConstants::declaredInSource($source))
        ->toBe([['name' => 'OUT_OF_ORDER_TARJETA', 'line' => 5]]);
})->group('RNF-M-06');
