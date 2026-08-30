<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * Las cuatro pruebas que ADR-021 y ADR-025 enuncian en su seccion "Verificacion"
 * (plan de implementacion, Fase 0, tarea 0.3, paso 4).
 *
 * Hoy los modulos estan practicamente vacios y las cuatro pasan por vacuidad.
 * Es deliberado y esta escrito en el plan: "una prueba de arquitectura sobre un
 * arbol vacio pasa, y empieza a proteger en cuanto haya codigo". Existen desde
 * ahora porque la tarea 1.1 escribe el nucleo, y para entonces la frontera ya
 * tiene que estar vigilada: descubrir la infraccion con el dominio escrito
 * cuesta el triple.
 */

const SATELLITE_MODULES = ['Identity', 'Workforce', 'Product', 'Compliance'];

// a) El nucleo sigue siendo nucleo. Es la que falla primero si alguien invierte
//    una flecha, y la que impide que Attendance acabe dependiendo de todos.
it('mantiene el nucleo Attendance sin importar nada de los satelites', function (): void {
    $offenders = [];

    foreach (ModuleTree::filesIn('Attendance') as $file) {
        foreach (ModuleTree::importsOf($file) as $import) {
            foreach (SATELLITE_MODULES as $satellite) {
                if (str_starts_with($import, 'App\\Modules\\'.$satellite.'\\')) {
                    $offenders[] = ModuleTree::relative($file).' importa '.$import;
                }
            }
        }
    }

    expect($offenders)->toBe([]);
})->group('RNF-M-03');

// b) La arista concedida por ADR-025 no se ensancha. Identity/Infrastructure y
//    Workforce/Infrastructure pueden alcanzar Attendance\Application\Port —ahi
//    viven los adaptadores HmacSignatureVerifier (1.5) y EloquentEmployee-
//    Directory (1.6)— y nada mas de Attendance: ni su Domain, ni sus casos de
//    uso. "Una excepcion es un agujero con nombre; esto es una arista declarada."
it('mantiene la arista de ADR-025 acotada a Attendance\\Application\\Port', function (): void {
    $allowed = 'App\\Modules\\Attendance\\Application\\Port\\';
    $offenders = [];

    foreach (['Identity', 'Workforce'] as $module) {
        foreach (ModuleTree::filesIn($module.'/Infrastructure') as $file) {
            foreach (ModuleTree::importsOf($file) as $import) {
                if (! str_starts_with($import, 'App\\Modules\\Attendance\\')) {
                    continue;
                }

                if (! str_starts_with($import, $allowed)) {
                    $offenders[] = ModuleTree::relative($file).' importa '.$import;
                }
            }
        }
    }

    expect($offenders)->toBe([]);
})->group('RNF-M-03');

// c) Los puertos del nucleo hablan en tipos de Shared o en escalares
//    (restriccion 2 de ADR-025). Es la que se erosiona sin darse cuenta: basta
//    que un puerto acepte un Employee de Workforce para que el nucleo dependa
//    de un satelite por la puerta de atras, sin que ningun import de Attendance
//    lo delate.
it('mantiene los puertos de Attendance libres de tipos de satelites y del framework', function (): void {
    $forbidden = [
        'App\\Modules\\Identity\\',
        'App\\Modules\\Workforce\\',
        'App\\Modules\\Product\\',
        'App\\Modules\\Compliance\\',
        'Illuminate\\',
    ];

    $offenders = [];

    foreach (ModuleTree::filesIn('Attendance/Application/Port') as $file) {
        foreach (ModuleTree::importsOf($file) as $import) {
            foreach ($forbidden as $prefix) {
                if (str_starts_with($import, $prefix)) {
                    $offenders[] = ModuleTree::relative($file).' expone '.$import;
                }
            }
        }
    }

    expect($offenders)->toBe([]);
})->group('RNF-M-03');

// d) Una sola declaracion de cada puerto transversal y de cada adaptador. Es la
//    verificacion explicita de ADR-021, y la segunda mitad —el adaptador— es la
//    que evita el modo de fallo real: que Compliance acabe con su propio
//    SystemClock en la tarea 2.2 y los dos relojes diverjan en silencio.
//
//    Los cuatro puertos y adaptadores de configuracion existen ya: los dos
//    puertos desde la tarea 1.1, `DbOperationalSettingsProvider` desde la 1.4
//    —RN-08 y RF-AT-06 necesitaban sus umbrales— y `DbCompliancePolicyProvider`
//    desde la 2.6, por el mismo motivo: RN-10, RN-11 y RN-12 no podian nacer
//    leyendo constantes de PHP (regla dura 14). Las tareas 5.1 y 5.2 anaden
//    encima la edicion desde el panel, no la lectura.
//
//    La prueba sigue exigiendo EXACTAMENTE UNA declaracion de cada uno: el modo
//    de fallo que evita —que un modulo acabe con su propia copia y las dos
//    diverjan en silencio— no desaparece porque el simbolo ya exista.
it('no permite dos declaraciones del mismo puerto transversal ni de su adaptador', function (string $symbol, bool $mustExistToday): void {
    $declarations = ModuleTree::declarationsOf($symbol);

    $paths = array_map(
        ModuleTree::relative(...),
        $declarations
    );

    $where = $paths === [] ? '(ninguno)' : implode(', ', $paths);

    // Nunca dos. Es la mitad de la regla que aplica a los seis simbolos.
    expect(\count($paths))->toBeLessThanOrEqual(1, $symbol.' declarado en: '.$where);

    // Y exactamente uno para los que ya deben existir hoy.
    if ($mustExistToday) {
        expect($paths)->toHaveCount(1, $symbol.' declarado en: '.$where);
    }
})->with([
    // [simbolo, debe existir ya hoy]
    'Clock (ADR-021)' => ['Clock', true],
    'SystemClock (ADR-021)' => ['SystemClock', true],
    'CompliancePolicyProvider (ADR-025, tarea 1.1)' => ['CompliancePolicyProvider', true],
    'OperationalSettingsProvider (ADR-025, tarea 1.1)' => ['OperationalSettingsProvider', true],
    'DbCompliancePolicyProvider (ADR-025, tarea 2.6)' => ['DbCompliancePolicyProvider', true],
    'DbOperationalSettingsProvider (ADR-025, tarea 1.4)' => ['DbOperationalSettingsProvider', true],
])->group('RNF-M-03');

// El corolario de ADR-021 que da sentido a la prueba d: el puerto vive en
// Application, no en Domain. Si alguien lo reubica, la regla de Deptrac que
// prohibe a */Domain depender del Domain de otro modulo tumbaria el reloj.
it('mantiene el puerto Clock en la capa de aplicacion de Shared', function (): void {
    expect(ModuleTree::relative(ModuleTree::declarationsOf('Clock')[0]))
        ->toBe('Shared/Application/Port/Clock.php');

    expect(ModuleTree::relative(ModuleTree::declarationsOf('SystemClock')[0]))
        ->toBe('Shared/Infrastructure/Adapter/SystemClock.php');
})->group('RNF-M-03');
