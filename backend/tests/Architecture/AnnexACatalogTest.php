<?php

declare(strict_types=1);

use App\Console\Commands\Quality\Support\AnnexA;
use App\Console\Commands\Quality\Support\RequirementCatalog;
use Tests\Architecture\Support\Repo;

/*
 * El Anexo A del doc 01 y docs/requisitos.yaml no pueden divergir.
 *
 * Por que existe este fichero. `qa:traceability` no lee el documento: lee el
 * YAML, que es la version procesable. Eso deja un hueco por el que se cuela
 * exactamente el fallo que la tarea 0.7 se escribio para erradicar: se añade
 * un requisito al Anexo A —autoridad #3— y no al YAML, y a partir de ahi
 * `--check` pasa en verde sin verlo, `docs:consistency` tambien (solo recorre
 * el catalogo), y el requisito llega a produccion sin prueba y sin tarea.
 *
 * Es el caso RF-ID-09 de la auditoria previa al arranque, repetido dentro de la
 * herramienta que lo detecta. La clase AnnexA existia desde la tarea 0.7 con un
 * docblock que decia «la usa la prueba que comprueba que las dos no han
 * divergido»; esa prueba es esta. Hasta que se escribio, AnnexA eran 163 lineas
 * de codigo muerto documentando una garantia inexistente, que es peor que no
 * tener la garantia: quien leyera el docblock la habria dado por hecha.
 *
 * Se lee el documento como texto, con los rangos, las negritas y las
 * anotaciones entre parentesis que tiene de verdad. Cualquier otra cosa seria
 * comprobar una copia contra otra copia.
 */

/**
 * @return array{annex: AnnexA, catalog: array<string, int>}
 */
function annexAndCatalog(): array
{
    $docs = Repo::file('docs');

    return [
        'annex' => AnnexA::fromFile($docs.'/01-especificaciones-proyecto.md'),
        'catalog' => RequirementCatalog::fromFile($docs.'/requisitos.yaml')->phases(),
    ];
}

it('no deja ningun requisito del Anexo A fuera del catalogo', function (): void {
    ['annex' => $annex, 'catalog' => $catalog] = annexAndCatalog();

    $missing = array_diff_key($annex->phases, $catalog);

    expect($missing)->toBe(
        [],
        'Estos requisitos estan en el Anexo A del doc 01 y no en docs/requisitos.yaml: '
        .implode(', ', array_keys($missing)).'. Mientras falten, `qa:traceability --check` '
        .'no los vigila: pasa en verde sin ellos.'
    );
})->group('RQ-13', 'RNF-M-04');

it('no inventa en el catalogo requisitos que el Anexo A no cita', function (): void {
    ['annex' => $annex, 'catalog' => $catalog] = annexAndCatalog();

    $invented = array_diff_key($catalog, $annex->phases);

    expect($invented)->toBe(
        [],
        'Estos requisitos estan en docs/requisitos.yaml y no los cita el Anexo A: '
        .implode(', ', array_keys($invented)).'. O sobran en el catalogo, o el documento '
        .'—que es autoridad #3— se quedo atras y hay que corregirlo alli primero.'
    );
})->group('RQ-13', 'RNF-M-04');

it('atribuye cada requisito a la misma fase en el documento y en el catalogo', function (): void {
    ['annex' => $annex, 'catalog' => $catalog] = annexAndCatalog();

    $divergent = [];

    foreach ($annex->phases as $id => $phase) {
        if (isset($catalog[$id]) && $catalog[$id] !== $phase) {
            $divergent[$id] = 'Anexo A dice fase '.$phase.', el catalogo dice fase '.$catalog[$id];
        }
    }

    // La fase no es una etiqueta descriptiva: decide CUANDO el requisito empieza
    // a ser exigible en `--check`. Adelantarla obliga a probar lo que aun no se
    // ha construido; atrasarla es el fallo de RN-11 y RN-12, que figuraban en la
    // Fase 2 del Anexo A y se implementaban en la 3.
    expect($divergent)->toBe([], 'Divergencia de fase: '.json_encode($divergent, JSON_UNESCAPED_UNICODE));
})->group('RQ-13', 'RNF-M-04');

it('mantiene el orden de ejecucion de las fases igual que el documento', function (): void {
    ['annex' => $annex] = annexAndCatalog();

    // Se lee el literal del fichero y no `config('quality.…')` porque la suite
    // Architecture corre sin arrancar Laravel (tests/Pest.php), que es lo que la
    // mantiene rapida. Leerlo como texto tiene ademas una ventaja: comprueba que
    // el valor SIGUE siendo un literal. El dia que alguien lo cambie por un
    // `env(...)`, esta expresion deja de casar y la prueba lo dice.
    $config = Repo::contents('backend/config/quality.php');

    preg_match('/\'phase_execution_order\'\s*=>\s*\[(?<order>[\d,\s]+)\]/', $config, $match);
    $literal = $match['order'] ?? '';

    expect($literal)->not->toBe(
        '',
        'phase_execution_order ha dejado de ser una lista literal en config/quality.php.'
    );

    preg_match_all('/\d+/', $literal, $numbers);
    $configured = array_map(intval(...), $numbers[0]);

    expect($annex->executionOrder)->toBe(
        $configured,
        'El Anexo A declara el orden '.implode(' -> ', $annex->executionOrder)
        .' y config/quality.php tiene '.implode(' -> ', $configured).'. El orden no es numerico '
        .'(0 -> 1 -> 2 -> 5 -> 3 -> 4) y de el depende que fases se consideran ya ejecutadas.'
    );
})->group('RQ-13', 'RNF-M-04');

it('deja constancia de las entradas del Anexo A que no citan ningun identificador', function (): void {
    ['annex' => $annex] = annexAndCatalog();

    // Estas NO son un fallo: «§9 completo» o «Cuadrantes, vacaciones con
    // aprobacion...» son entradas reales del Anexo A que describen trabajo sin
    // nombrar requisitos. La prueba no las prohibe, las hace visibles: si un dia
    // aparece ahi algo que si deberia tener identificador, se vera en el mensaje
    // en vez de desaparecer en silencio, que es como se pierden los requisitos.
    expect($annex->uncoded)->toBeArray();

    foreach ($annex->uncoded as $entry) {
        expect($entry['text'])->not->toMatch(
            '/\b(?:RF|RN|RL|RS|RQ|RNF)-[A-Z]{1,3}?-?\d/u',
            'La entrada «'.$entry['text'].'» de la Fase '.$entry['phase'].' del Anexo A cita algo que '
            .'parece un requisito y el lector de rangos no lo ha reconocido. Revisa su formato en el '
            .'documento: si no se lee, no se vigila.'
        );
    }
})->group('RQ-13', 'RNF-M-04');
