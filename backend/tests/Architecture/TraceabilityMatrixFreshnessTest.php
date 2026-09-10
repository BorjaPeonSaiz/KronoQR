<?php

declare(strict_types=1);

use App\Console\Commands\Quality\Support\RequirementCatalog;
use App\Console\Commands\Quality\Support\TagScanner;
use Tests\Architecture\Support\Repo;

/*
 * `docs/trazabilidad-pruebas.md` no puede quedarse atras (RQ-13, doc 02 §9.6).
 *
 * ## El hueco que cierra
 *
 * `qa:traceability --check` bloquea la CI cuando un requisito implementado no
 * tiene ninguna prueba. Lo que NO mira es la matriz versionada: es un fichero
 * generado que se escribe a mano —`make traceability` desde el anfitrion— y que
 * nadie vuelve a mirar. Entre el cierre de la Fase 5 y esta prueba, la matriz
 * declaraba 2.712 pruebas etiquetadas y la fase en curso 2, con 2.756 pruebas
 * en el arbol. Es decir: **la evidencia documental de que cada obligacion legal
 * esta verificada describia un arbol que ya no existe**, y eso es peor que no
 * tenerla, porque quien la lee la da por buena.
 *
 * ## Por que compara cifras y no el fichero entero
 *
 * Un `diff` completo seria la comprobacion ideal y no se puede hacer: la matriz
 * lleva las rutas de cada prueba **relativas al padre de `base_path()`**, que en
 * el contenedor es `html/tests/…` y en la CI —sin contenedor— seria
 * `backend/tests/…`. Comparar el texto pondria la prueba en rojo en uno de los
 * dos sitios haga lo que haga quien la regenere, y una prueba que solo pasa en
 * una maquina no verifica nada.
 *
 * Las tres cifras del apartado «Alcance» si son independientes del entorno, y
 * son justo las que se quedan obsoletas: cuantos requisitos hay en el catalogo,
 * cuantas pruebas etiquetadas se han encontrado —por herramienta— y en que fase
 * se declara el repositorio. Cualquier etiqueta añadida, retirada o cambiada
 * mueve una de las tres.
 *
 * ## Y el recuento se RECALCULA, no se lee de otro sitio
 *
 * Con el mismo extractor que usa el comando ({@see TagScanner}), sobre los
 * mismos directorios. Leer la cifra de dos ficheros distintos solo comprobaria
 * que dos copias coinciden.
 */

/**
 * Las pruebas etiquetadas que hay en el arbol, por herramienta.
 *
 * Los directorios son los mismos que declara `quality.test_paths` y la ruta base
 * la misma que pasa el comando (`dirname(base_path())`). Se explora una sola
 * vez: son 400 ficheros y las cuatro pruebas de abajo miran el mismo resultado.
 *
 * @return array<string, int>
 */
function pruebasEtiquetadasPorHerramienta(): array
{
    static $recuento = null;

    if ($recuento !== null) {
        return $recuento;
    }

    $backend = \dirname(__DIR__, 2);

    $scan = (new TagScanner([
        'pest' => [$backend.'/tests'],
        'playwright' => [
            Repo::file('frontend-kiosk/tests/e2e'),
            Repo::file('frontend-admin/tests/e2e'),
            Repo::file('frontend-portal/tests/e2e'),
        ],
    ], \dirname($backend)))->scan();

    return $recuento = [
        'pest' => \count($scan->by('pest')),
        'playwright' => \count($scan->by('playwright')),
    ];
}

/** Una cifra en negrita del apartado «Alcance» de la matriz versionada. */
function cifraDeLaMatriz(string $patron): int
{
    preg_match($patron, Repo::contents('docs/trazabilidad-pruebas.md'), $encontrado);

    expect($encontrado)->not->toBe(
        [],
        'El apartado «Alcance» de docs/trazabilidad-pruebas.md ya no tiene la forma que esta prueba '
        .'sabe leer ('.$patron.'). Si ha cambiado el formato de `TraceabilityReport::toMarkdown()`, '
        .'actualiza aqui la expresion: sin ella nadie vigila que la matriz este al dia.'
    );

    return (int) ($encontrado[1] ?? '');
}

/** El literal `current_phase` de config/quality.php, leido como texto. */
function faseEnCursoDeclarada(): int
{
    preg_match('/\'current_phase\'\s*=>\s*(\d+)/', Repo::contents('backend/config/quality.php'), $encontrado);

    expect($encontrado)->not->toBe([], 'current_phase ha dejado de ser un literal en config/quality.php.');

    return (int) ($encontrado[1] ?? '');
}

/** El mismo mensaje para las cuatro: la matriz se regenera FUERA del contenedor. */
const COMO_REGENERAR = ' Regenera la matriz desde el anfitrion con «make traceability» '
    .'(dentro del contenedor no se puede: docs/ va montado de solo lectura, y por eso el objetivo '
    .'ejecuta «qa:traceability --output=-» y escribe el fichero fuera). Despues, commit del fichero '
    .'generado junto al cambio de etiquetas que lo movio.';

it('declara en la matriz el mismo numero de requisitos que tiene el catalogo', function (): void {
    $catalogo = RequirementCatalog::fromFile(Repo::file('docs/requisitos.yaml'));

    expect(cifraDeLaMatriz('/Catálogo: `docs\/requisitos\.yaml`, \*\*(\d+) requisitos\*\*/u'))
        ->toBe(\count($catalogo->requirements), 'La matriz habla de un catalogo de otro tamaño.'.COMO_REGENERAR);
})->group('RQ-13');

it('declara en la matriz las pruebas de Pest que hay de verdad en el arbol', function (): void {
    expect(cifraDeLaMatriz('/Pruebas etiquetadas: \*\*\d+\*\* \(Pest (\d+),/u'))
        ->toBe(pruebasEtiquetadasPorHerramienta()['pest'], 'La matriz cuenta otras pruebas de Pest.'.COMO_REGENERAR);
})->group('RQ-13');

it('declara en la matriz las pruebas de Playwright que hay de verdad en el arbol', function (): void {
    // Aparte de las de Pest a proposito: el E2E vive fuera de `backend/` y es lo
    // primero que se queda sin contar cuando alguien regenera la matriz desde un
    // sitio donde los frontends no estan montados.
    expect(cifraDeLaMatriz('/Pruebas etiquetadas: \*\*\d+\*\* \(Pest \d+, Playwright (\d+)\)/u'))
        ->toBe(pruebasEtiquetadasPorHerramienta()['playwright'], 'La matriz cuenta otras pruebas de Playwright.'.COMO_REGENERAR);
})->group('RQ-13');

it('declara en la matriz la misma fase en curso que config/quality.php', function (): void {
    // Es la cifra que decide QUE requisitos bloquean. Una matriz que dice «fase 2»
    // cuando el repositorio ya cerro la 5 presenta como no exigibles veintitres
    // requisitos que si lo son.
    expect(cifraDeLaMatriz('/Fase en curso \(`quality\.current_phase`\): \*\*(\d+)\*\*/u'))
        ->toBe(faseEnCursoDeclarada(), 'La matriz declara otra fase en curso.'.COMO_REGENERAR);
})->group('RQ-13');
