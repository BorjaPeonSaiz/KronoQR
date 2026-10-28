<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\Repo;

/*
 * **LA TABLA DEL DOC 02 §1.6 DICE LO MISMO QUE `deptrac.yaml`** (RNF-M-03).
 *
 * La tabla «Puede depender de» es por donde entra quien quiere saber que modulo
 * puede ver a cual, y Deptrac es lo que de verdad lo impone. Se separaron sin
 * que nada lo notara: Deptrac concedia a `Compliance` eventos de cuatro modulos
 * que la tabla no nombraba, y a `Kiosk` los casos de uso de `Identity`.
 *
 * Se compara a nivel de modulo, no de capa: por cada modulo, el conjunto de
 * los OTROS modulos (sin `Shared`) de los que alguna de sus capas puede
 * depender segun el `ruleset`, frente a los que nombra su fila. La forma de la
 * arista —evento, puerto, caso de uso— la describe la tabla y la impone
 * Deptrac; aqui solo se exige que los dos hablen de los mismos modulos.
 */

/** Los modulos que cuentan como aristas: todos menos `Shared`, al que pueden ir todos. */
const MODULE_BOUNDARIES_TABLE_MODULES = ['Attendance', 'Compliance', 'Workforce', 'Identity', 'Reporting', 'Kiosk', 'Product'];

function moduloDeLaCapa(string $layer): ?string
{
    foreach (ModuleTree::MODULES as $module) {
        if (str_starts_with($layer, $module)) {
            return $module;
        }
    }

    return null;
}

/**
 * Modulo => otros modulos de los que puede depender, segun Deptrac.
 *
 * @return array<string, list<string>>
 */
function aristasDeDeptrac(): array
{
    /** @var array{parameters: array{ruleset: array<string, list<string>|null>}} $config */
    $config = Yaml::parseFile(Repo::file('backend/deptrac.yaml'));
    $edges = array_fill_keys(MODULE_BOUNDARIES_TABLE_MODULES, []);

    foreach ($config['parameters']['ruleset'] as $layer => $allowed) {
        $from = moduloDeLaCapa($layer);

        if ($from === null || $from === 'Shared') {
            continue;
        }

        foreach ($allowed ?? [] as $target) {
            $to = moduloDeLaCapa($target);

            if ($to !== null && $to !== 'Shared' && $to !== $from) {
                $edges[$from][] = $to;
            }
        }
    }

    return array_map(static function (array $modules): array {
        $modules = array_values(array_unique($modules));
        sort($modules);

        return $modules;
    }, $edges);
}

/**
 * Modulo => otros modulos que nombra su celda «Puede depender de».
 *
 * @return array<string, list<string>>
 */
function aristasDeLaTabla(): array
{
    $doc = (string) file_get_contents(Repo::file('docs/02-stack-tecnologico-y-plan-implementacion.md'));
    $start = strpos($doc, '### 1.6 ');

    expect($start)->not->toBeFalse('El doc 02 ya no tiene la seccion «### 1.6».');

    $section = preg_split('/^#{2,3} /m', substr($doc, (int) $start + 4))[0] ?? '';
    $edges = [];

    foreach (preg_split('/\R/', $section) ?: [] as $line) {
        if (preg_match('/^\| `(\w+)` \|[^|]*\|(?<cell>[^|]*)\|\s*$/u', $line, $row) !== 1
            || ! \in_array($row[1], MODULE_BOUNDARIES_TABLE_MODULES, true)) {
            continue;
        }

        preg_match_all('/`(\w+)[`\/]/', $row['cell'], $named);
        $modules = array_values(array_unique(array_filter(
            $named[1],
            static fn (string $name): bool => $name !== $row[1] && \in_array($name, MODULE_BOUNDARIES_TABLE_MODULES, true),
        )));
        sort($modules);
        $edges[$row[1]] = $modules;
    }

    return $edges;
}

it('la tabla del doc 02 §1.6 nombra los mismos modulos que concede deptrac.yaml', function (): void {
    $deptrac = aristasDeDeptrac();
    $tabla = aristasDeLaTabla();

    expect(array_keys($tabla))->toEqualCanonicalizing(MODULE_BOUNDARIES_TABLE_MODULES, 'Faltan filas de modulo en la tabla del doc 02 §1.6.');

    foreach ($deptrac as $module => $modules) {
        expect($tabla[$module] ?? [])->toBe($modules, 'La fila de '.$module.' del doc 02 §1.6 no nombra los mismos modulos '
            .'que deptrac.yaml le concede. Deptrac: '.implode(', ', $modules).'. Corrige la que este mal: '
            .'la tabla es la que se lee y Deptrac la que se cumple.');
    }
})->group('RNF-M-03');

it('lee aristas de verdad en los dos lados', function (): void {
    // Sin esto, dos lectores rotos devolverian dos listas vacias iguales.
    expect(aristasDeDeptrac()['Compliance'])->toContain('Attendance')
        ->and(aristasDeLaTabla()['Kiosk'] ?? [])->toContain('Attendance');
})->group('RNF-M-03');
