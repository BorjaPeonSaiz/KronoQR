<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * La lectura de `audit_log` no puede exigir que la accion este en el catalogo
 * (RS-07, RL-04, RF-PD-10).
 *
 * POR QUE ESTA PRUEBA EXISTE. El defecto que cierra —etapa ⑧b de la CI, cierre
 * de Fase 5— no fue una regla de negocio mal escrita: fue **una llamada**.
 * `AuditAction::from()` en el camino de lectura, que revienta con `ValueError`
 * en cuanto la fila la escribio una version mas nueva. Eso pasa siempre que se
 * deshace una actualizacion, porque las acciones nuevas las estrena la version
 * siguiente. La correccion es `AuditActionName::fromStorage()`, y lo que
 * garantiza que nadie vuelva a escribir la llamada de antes no es el docblock:
 * es esta prueba (doc 02 §3.5, «una convencion que no verifica una herramienta
 * es una sugerencia»).
 *
 * Se recorre con `ModuleTree::phpFilesUnder()`, que usa `scandir`: el iterador
 * recursivo pierde ficheros sobre el bind mount de Docker Desktop y daria verde
 * sin haber mirado (ver HANDOFF → «Trampas»).
 */

it('no resuelve el enum de acciones con from() en ningun sitio', function (): void {
    // `AuditAction::from()` solo puede aparecer donde el valor NO viene de la
    // base de datos, y hoy no hace falta en ninguna parte: quien escribe ya
    // tiene el caso del enum y quien lee usa `AuditActionName::fromStorage()`.
    // Si algun dia hiciera falta de verdad, la excepcion se declara aqui, con
    // su motivo, y no en silencio dentro de un adaptador.
    $offenders = [];

    foreach (ModuleTree::phpFilesUnder(ModuleTree::root()) as $file) {
        $contents = file_get_contents($file);

        if ($contents === false) {
            continue;
        }

        foreach (explode("\n", $contents) as $number => $line) {
            $code = ltrim($line);

            // Los comentarios explican por que NO se usa: no son la llamada.
            if ($code === '' || str_starts_with($code, '*') || str_starts_with($code, '//') || str_starts_with($code, '/*')) {
                continue;
            }

            if (str_contains($code, 'AuditAction::from(')) {
                $offenders[] = ModuleTree::relative($file).':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'Se resuelve el catalogo de acciones con `AuditAction::from()`, que lanza `ValueError` ante una '
        .'accion que esta version no conoce. La cadena de auditoria se verifica por HASH, no por catalogo '
        .'(doc 02 §7.4): use `AuditActionName::fromStorage()` para leer y `AuditAction` solo para escribir. '
        .'Ficheros: '.implode(', ', $offenders)
    );
})->group('RS-07', 'RL-04', 'RF-PD-10');
