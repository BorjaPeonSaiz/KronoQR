<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * Un identificador nulo falla, no se convierte en cero (R1-AR-03).
 *
 * `$entidad->id ?? 0` al publicar un hecho dejaba en `audit_log` un asiento
 * sobre el sujeto 0, y en el verificador de la cadena una rotura en la fila 0:
 * dos cosas que no existen y que nadie ve hasta que hace falta encontrarlas.
 * La forma correcta es `persistedId()`, que lanza si la fila no se ha guardado,
 * o un identificador que el tipo ya garantiza.
 */

it('no convierte en cero el identificador de una entidad en Domain ni en Application', function (): void {
    $offenders = [];

    foreach ([...ModuleTree::filesInLayer(ModuleTree::MODULES, 'Domain'), ...ModuleTree::filesInLayer(ModuleTree::MODULES, 'Application')] as $file) {
        $lines = file($file) ?: [];

        foreach ($lines as $number => $line) {
            if (preg_match('/->id\s*\?\?\s*0(?!\d)/', $line) === 1) {
                $offenders[] = ModuleTree::relative($file).':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
})->group('RL-04', 'RNF-M-03');
