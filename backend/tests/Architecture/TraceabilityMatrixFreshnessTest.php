<?php

declare(strict_types=1);

use App\Console\Commands\Quality\Support\PhaseOrder;
use App\Console\Commands\Quality\Support\RequirementCatalog;
use App\Console\Commands\Quality\Support\TagScanner;
use App\Console\Commands\Quality\Support\TraceabilityReport;
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
 * ## Compara el fichero entero, no solo las cifras
 *
 * Hasta la 2.3.0 solo comparaba las tres cifras del apartado «Alcance» (V21-1-QA-2):
 * una matriz con el mismo numero de pruebas pero con otras —una etiqueta movida
 * de requisito, un nombre atribuido a la prueba equivocada, que es lo que fue
 * V1-QA-1— pasaba en verde. Ahora se genera la matriz con las mismas piezas
 * que `qa:traceability` y se compara con la versionada **entera**.
 *
 * Lo unico que depende de donde se genera son las rutas, que van **relativas
 * al padre de `base_path()`** —`html/tests/…` y `/var/www/repo/frontend-…` en el
 * contenedor, `backend/tests/…` y `frontend-…` en la CI—, y con ellas el ORDEN
 * de las pruebas de una misma celda, que se ordenan por ruta. Se normalizan en
 * los dos lados ({@see matrizNormalizada()}) y se compara el contenido sin el
 * orden ({@see matrizComparable()}): las mismas lineas, cada una con las mismas
 * pruebas. Una prueba de mas, de menos, con otro nombre o bajo otro requisito
 * es una diferencia. Las cifras se siguen comprobando aparte porque su mensaje
 * dice QUE se ha movido.
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
 * vez: son 400 ficheros y las cinco pruebas de abajo miran el mismo resultado.
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
        'k6' => [Repo::file('load-tests/k6')],
    ], \dirname($backend)))->scan();

    return $recuento = [
        'pest' => \count($scan->by('pest')),
        'playwright' => \count($scan->by('playwright')),
        'k6' => \count($scan->by('k6')),
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

/**
 * La matriz que generaria hoy `qa:traceability`, con las mismas piezas que
 * el comando: el catalogo, el escaner, el orden y la fase de
 * `config/quality.php` y la lista de `quality.skipped_in_ci`, leidos como
 * literales porque esta suite no arranca el framework.
 */
function matrizRegenerada(): string
{
    $backend = \dirname(__DIR__, 2);
    $config = Repo::contents('backend/config/quality.php');

    preg_match('/\'phase_execution_order\'\s*=>\s*\[([\d,\s]+)\]/', $config, $orden);
    preg_match('/\'skipped_in_ci\'\s*=>\s*\[(?<entries>[^\]]*)\]/', $config, $omitidas);
    preg_match_all('/\'(?<file>[^\']+)\'\s*=>\s*\d+/', $omitidas['entries'] ?? '', $ficheros);

    $scan = (new TagScanner([
        'pest' => [$backend.'/tests'],
        'playwright' => [
            Repo::file('frontend-kiosk/tests/e2e'),
            Repo::file('frontend-admin/tests/e2e'),
            Repo::file('frontend-portal/tests/e2e'),
        ],
        'k6' => [Repo::file('load-tests/k6')],
    ], \dirname($backend)))->scan();

    return (new TraceabilityReport(
        RequirementCatalog::fromFile(Repo::file('docs/requisitos.yaml')),
        $scan,
        new PhaseOrder(array_map(intval(...), array_map(trim(...), explode(',', $orden[1] ?? '')))),
        faseEnCursoDeclarada(),
        $ficheros['file'],
    ))->toMarkdown();
}

/**
 * La matriz con las rutas llevadas a la forma de la CI, sea cual sea el
 * directorio desde el que se genero: las de Pest a `backend/tests/…` (en el
 * contenedor son `html/tests/…`) y las de Playwright y k6 a
 * `frontend-*\/tests/e2e/…` y `load-tests/k6/…` (en el contenedor llegan con el
 * montaje delante, `/var/www/repo/…`).
 */
function matrizNormalizada(string $matriz): string
{
    return (string) preg_replace(
        [
            '#[\w.-]+/tests/(?=(?:Unit|Integration|Feature|Contract|Architecture|Restore)/)#',
            '#(?<![\w./-])[\w./-]*/(?=(?:frontend-(?:kiosk|admin|portal)/tests/e2e|load-tests/k6)/)#',
        ],
        ['backend/tests/', ''],
        str_replace("\r\n", "\n", $matriz),
    );
}

/**
 * La matriz normalizada, sin el orden que depende del entorno: cada linea con
 * sus pruebas (separadas por `<br>`) ordenadas, y las lineas ordenadas.
 *
 * @return list<string>
 */
function matrizComparable(string $matriz): array
{
    $lineas = array_map(static function (string $linea): string {
        $pruebas = explode('<br>', $linea);
        sort($pruebas);

        return implode('<br>', $pruebas);
    }, explode("\n", matrizNormalizada($matriz)));

    sort($lineas);

    return $lineas;
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
    expect(cifraDeLaMatriz('/Pruebas etiquetadas: \*\*\d+\*\* \(Pest \d+, Playwright (\d+),/u'))
        ->toBe(pruebasEtiquetadasPorHerramienta()['playwright'], 'La matriz cuenta otras pruebas de Playwright.'.COMO_REGENERAR);
})->group('RQ-13');

it('declara en la matriz los escenarios de k6 que hay de verdad en el arbol', function (): void {
    // Aparte tambien, y por el mismo motivo que Playwright: la prueba de carga
    // vive fuera de `backend/`. Ademas es la UNICA cobertura automatica de
    // RNF-P-06 y de RQ-08, asi que si su recuento se cae, lo que se cae de la
    // matriz es la evidencia de que el umbral de rendimiento se mide.
    expect(cifraDeLaMatriz('/Pruebas etiquetadas: \*\*\d+\*\* \(Pest \d+, Playwright \d+, k6 (\d+)\)/u'))
        ->toBe(pruebasEtiquetadasPorHerramienta()['k6'], 'La matriz cuenta otros escenarios de k6.'.COMO_REGENERAR);
})->group('RQ-13');

it('declara en la matriz la misma fase en curso que config/quality.php', function (): void {
    // Es la cifra que decide QUE requisitos bloquean. Una matriz que dice «fase 2»
    // cuando el repositorio ya cerro la 5 presenta como no exigibles veintitres
    // requisitos que si lo son.
    expect(cifraDeLaMatriz('/Fase en curso \(`quality\.current_phase`\): \*\*(\d+)\*\*/u'))
        ->toBe(faseEnCursoDeclarada(), 'La matriz declara otra fase en curso.'.COMO_REGENERAR);
})->group('RQ-13');

it('versiona exactamente la matriz que genera qa:traceability', function (): void {
    $versionada = matrizComparable(Repo::contents('docs/trazabilidad-pruebas.md'));
    $regenerada = matrizComparable(matrizRegenerada());

    // Sin volcar 1,8 MB en el mensaje: lo que sobra en cada lado, recortado,
    // basta para saber que hay que regenerar; `git diff` despues dice el resto.
    $sobraEnLaVersionada = array_values(array_diff($versionada, $regenerada));
    $faltaEnLaVersionada = array_values(array_diff($regenerada, $versionada));

    expect($versionada === $regenerada)->toBeTrue(
        "La matriz versionada no es la que genera el arbol de hoy.\n  solo en la versionada: "
        .mb_substr($sobraEnLaVersionada[0] ?? '(nada)', 0, 200)."\n  solo en la regenerada: "
        .mb_substr($faltaEnLaVersionada[0] ?? '(nada)', 0, 200)."\n".COMO_REGENERAR,
    );
})->group('RQ-13');

it('lleva las rutas a la forma de la CI, sea cual sea el directorio desde el que se genero', function (string $ruta, string $normalizada): void {
    expect(matrizNormalizada('- `'.$ruta.'`'))->toBe('- `'.$normalizada.'`');
})->with([
    'contenedor' => ['html/tests/Unit/Attendance/Domain/MidnightShiftTest.php:40', 'backend/tests/Unit/Attendance/Domain/MidnightShiftTest.php:40'],
    'una copia' => ['kq22a/tests/Feature/Health/ReadinessProbeTest.php:30', 'backend/tests/Feature/Health/ReadinessProbeTest.php:30'],
    'la CI' => ['backend/tests/Integration/Reporting/PeriodReportVolumeTest.php:9', 'backend/tests/Integration/Reporting/PeriodReportVolumeTest.php:9'],
    'playwright en la CI' => ['frontend-kiosk/tests/e2e/scan.spec.ts:51', 'frontend-kiosk/tests/e2e/scan.spec.ts:51'],
    'playwright desde el montaje del contenedor' => ['/var/www/repo/frontend-admin/tests/e2e/shell.spec.ts:61', 'frontend-admin/tests/e2e/shell.spec.ts:61'],
    'k6 desde el montaje del contenedor' => ['/var/www/repo/load-tests/k6/scan-peak.js:120', 'load-tests/k6/scan-peak.js:120'],
])->group('RQ-13');
