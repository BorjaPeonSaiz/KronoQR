<?php

declare(strict_types=1);

use Tests\Architecture\Support\CommentReferences;
use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\PendingDomainCommentCleanup;

/*
 * LOS COMENTARIOS DE `app/` Y DE `lang/` DICEN EL PORQUE, NO LA HISTORIA DEL
 * PLAN (doc 02 §3.5, RNF-M-06).
 *
 * Un comentario que dice «(bloque 25)», «la tarea 3.1 hizo…» o «(V21-1-RV-5)»
 * cuenta en que tanda o en que revision se escribio el codigo. Para quien lo
 * lea dentro de un año son numeros de un plan cerrado y no explican nada; el
 * motivo —el requisito, el ADR, la regla dura— si. La verificacion de la 2.2.1
 * encontro que estas referencias volvian despues de cada limpieza porque
 * ninguna herramienta lo impedia. Las SPA tienen su regla de ESLint
 * (`kronoqr-comments/no-plan-references`); esta es la del backend, con el
 * mismo criterio ({@see CommentReferences}).
 *
 * Tambien impide justificar el codigo con «varios centros» o «traslados» entre
 * centros: hay un centro por instalacion y su zona queda fija desde el primer
 * fichaje (ADR-040, ADR-056), y un comentario que razona con el modelo
 * contrario invita a volver a construirlo.
 *
 * QUE QUEDA FUERA, y por que:
 *
 * - `Console/Commands/Quality/Support/PlanIndex.php` y `PlanTask.php`: leen el
 *   plan de implementacion para la matriz de trazabilidad (RQ-13), y sus
 *   comentarios citan el formato de un encabezado de tarea, que es su dato.
 * - {@see PendingDomainCommentCleanup}: los ficheros de `Domain/` pendientes,
 *   con su motivo escrito alli. La lista solo encoge.
 */

const COMMENTS_WITHOUT_PLAN_REFERENCES_EXEMPT = [
    'Console/Commands/Quality/Support/PlanIndex.php',
    'Console/Commands/Quality/Support/PlanTask.php',
];

it('reconoce las referencias al plan y deja pasar el motivo (red de seguridad)', function (): void {
    $plan = [
        '(bloque 25 de la 2.2.1)', 'la tarea 3.1 las publica', '(tareas 1.10 y 1.13)',
        '(task 5.10)', '2.3.0 block 3', '(decision 13c de la ficha)',
        '(ficha 5.10 punto 8)', '(decision 7 de la ficha 3.9)', 'desde la 1.8',
        '(V21-1-RV-5)', '(R4-SC-05)', '(KI7-01)', '(PIN-02, regla dura 8)',
        '(C1)', '(RF-QR-08, HC1)', "// G1: el porque\n", 'revision de seguridad del bloque 14',
        'la segunda vuelta', 'cierre de la Fase 3', "(decision 13d de la ficha\n * 3.11)",
    ];
    $motivo = [
        '(RN-05, ADR-006, regla dura 4)', 'desde la 2.2.0', 'hasta la 2.2.x',
        '(ADR-045 C3)', '(ADR-045 §g, condicion C4)', '(ADR-061 A2)', '(ADR-048 decision 9)',
        '(ADR-020, T1199)', 'el prefijo FH1 del QR', 'una hoja A4', 'revision humana',
        'la primera iteracion del cursor', 'doc 02 §8.2', 'decision del propietario del 18-10-2026',
    ];

    foreach ($plan as $texto) {
        expect(CommentReferences::planReference($texto))->not->toBeNull($texto);
    }

    foreach ($motivo as $texto) {
        expect(CommentReferences::planReference($texto))->toBeNull($texto);
    }
})->group('RNF-M-06');

it('reconoce la justificacion con varios centros y deja pasar el centro unico', function (): void {
    foreach (['un traslado de centro no reescribe', 'el dia que haya varios centros', 'un tramo de otro centro', 'se le cambia de centro'] as $texto) {
        expect(CommentReferences::multiSiteReference($texto))->not->toBeNull($texto);
    }

    foreach (['hay un centro por instalacion (ADR-040)', 'el cambio de departamento', 'un traslado de departamento', 'se traslada a la zona del centro'] as $texto) {
        expect(CommentReferences::multiSiteReference($texto))->toBeNull($texto);
    }
})->group('RNF-M-06');

it('ningun comentario de app/ cita el plan, una revision o varios centros', function (): void {
    $root = dirname(ModuleTree::root());
    $exempt = [...COMMENTS_WITHOUT_PLAN_REFERENCES_EXEMPT, ...PendingDomainCommentCleanup::FILES];
    $files = ModuleTree::phpFilesUnder($root);
    $offenders = [];

    foreach ($files as $file) {
        $relative = ModuleTree::relative($file, $root);

        if (in_array($relative, $exempt, true)) {
            continue;
        }

        foreach (CommentReferences::findingsIn((string) file_get_contents($file)) as $finding) {
            $offenders[] = $relative.':'.$finding;
        }
    }

    expect(count($files))->toBeGreaterThan(1000)
        ->and($offenders)->toBe([], 'Comentarios que cuentan la historia del plan en vez del porque');
})->group('RNF-M-06');

it('la lista temporal de Domain/ solo tiene ficheros que aun lo necesitan', function (): void {
    $root = dirname(ModuleTree::root());
    $stale = [];

    foreach (PendingDomainCommentCleanup::FILES as $relative) {
        $path = $root.'/'.$relative;

        if (! str_contains($relative, '/Domain/') || ! is_file($path) || CommentReferences::findingsIn((string) file_get_contents($path)) === []) {
            $stale[] = $relative;
        }
    }

    expect($stale)->toBe([], 'Ya limpios, fuera de Domain/ o inexistentes: quitalos de PendingDomainCommentCleanup');
})->group('RNF-M-06');

it('ningun comentario de lang/ cita el plan, una revision o varios centros', function (): void {
    /*
     * Los ficheros de idioma explican a quien traduce por que dice lo que dice;
     * el mismo criterio que el codigo, en los dos idiomas (`task 5.10`,
     * `block 3` tambien cuentan).
     */
    $lang = dirname(ModuleTree::root(), 2).'/lang';
    $files = ModuleTree::phpFilesUnder($lang);
    $offenders = [];

    foreach ($files as $file) {
        foreach (CommentReferences::findingsIn((string) file_get_contents($file)) as $finding) {
            $offenders[] = ModuleTree::relative($file, $lang).':'.$finding;
        }
    }

    expect(count($files))->toBeGreaterThan(50)
        ->and($offenders)->toBe([], 'Comentarios de lang/ que cuentan la historia del plan en vez del porque');
})->group('RNF-M-06');
