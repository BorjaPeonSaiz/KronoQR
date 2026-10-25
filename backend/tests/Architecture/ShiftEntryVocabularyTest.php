<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * El tramo es `ShiftEntry`, nunca `Shift` a secas ni `Segment` (glosario del
 * doc 01 §13 y CLAUDE.md, C6).
 *
 * Dos nombres para la misma cosa es justo lo que el lenguaje ubicuo existe para
 * evitar: `CorrectShiftHandler` corregia un tramo, `ComplianceShiftSegment` era
 * un tramo y `ShiftTimes` eran las marcas de un tramo. Se vigila el nombre de
 * cada clase, interfaz, enum y trait de los modulos.
 *
 * **Lo que queda fuera, y por que**: `ShiftMarks`, porque copia el esquema
 * `ShiftMarks` del contrato (docs/api/openapi.yaml), que manda sobre la forma
 * de la respuesta y no se renombra desde aqui.
 */

it('no nombra un tramo como Shift ni como Segment', function (): void {
    $allowed = ['ShiftMarks'];
    $offenders = [];

    foreach (ModuleTree::phpFilesUnder(ModuleTree::root()) as $file) {
        $source = (string) file_get_contents($file);

        if (preg_match_all('/^(?:(?:final|abstract|readonly)\s+)*(?:class|interface|enum|trait)\s+(\w+)/m', $source, $matches) === 0) {
            continue;
        }

        foreach ($matches[1] as $name) {
            if (in_array($name, $allowed, true)) {
                continue;
            }

            if (preg_match('/Shift(?!Entr)|Segment/', $name) === 1) {
                $offenders[] = ModuleTree::relative($file).' declara '.$name;
            }
        }
    }

    expect($offenders)->toBe([]);
})->group('RNF-M-03');
