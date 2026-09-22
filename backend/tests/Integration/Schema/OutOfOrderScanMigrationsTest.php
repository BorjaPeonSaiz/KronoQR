<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\CommittedDatabase;

/*
 * Las tres migraciones expand de RN-18, deshechas y reaplicadas (skill
 * `/migracion-segura`: «una migracion cuyo `down()` no se ha probado no tiene
 * `down()`»).
 *
 * POR QUE APARTE DE `MigrationsRoundTripTest`. Aquel deshace TODO el esquema
 * sobre volumen realista y afirma que las invariantes vuelven; este mira la
 * vuelta atras **parcial**, que es la que de verdad se ejecuta en un servidor:
 * un despliegue que sale mal no borra la base, retrocede un paso. Lo que se
 * comprueba es lo unico que distingue un `down()` bueno de uno decorativo: que
 * la restriccion reconstruida sea **exactamente** la anterior, y no una parecida
 * que deje pasar el valor nuevo.
 *
 * SE COMPARA LA DEFINICION, NO EL COMPORTAMIENTO. `pg_get_constraintdef()`
 * devuelve el `CHECK` tal y como lo tiene el motor; una fila de prueba solo
 * demostraria que rechaza **ese** valor. Ademas, insertar filas obligaria a
 * sembrar empleados y quioscos en una base sin transaccion de prueba, y lo que
 * se prueba aqui es el esquema.
 *
 * ## Por que `CommittedDatabase` y no `RefreshDatabase`
 *
 * Por lo mismo que en `MigrationsRoundTripTest`: `migrate:rollback` corre con el
 * **rol de migracion**, que es otra conexion (ADR-033, regla dura 6), y no puede
 * esperar a que se cierre la transaccion de la prueba. Aqui todo se confirma.
 */

uses(CommittedDatabase::class);

/** Las tres migraciones de la tarea ad hoc, en el orden en que se aplican. */
const MIGRACIONES_DE_RN18 = [
    '2026_09_18_100000_allow_out_of_order_scan_result',
    '2026_09_18_100100_exempt_out_of_order_scan_from_worked_minutes',
    '2026_09_18_100200_allow_out_of_order_scan_incident_type',
];

/**
 * La definicion que el motor tiene de un `CHECK`, en una sola linea.
 */
function definicionDe(string $constraint): string
{
    $definition = DB::connection(config()->string('database.migrations.connection'))
        ->selectOne(
            'SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ?',
            [$constraint],
        );

    /** @var object{definition: string}|null $definition */
    return $definition === null ? '' : (string) $definition->definition;
}

it('deshace y reaplica las tres migraciones de RN-18 dejando el CHECK anterior exacto', function (): void {
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);

    // Cuantos pasos hay que deshacer para dejar el esquema **justo antes** de la
    // primera migracion de RN-18. Se calcula y no se fija en tres: el dia que
    // alguien añada una migracion detras, un `--step=3` clavado deshace su
    // trabajo en lugar del de esta tarea, y la prueba seguiria en verde mintiendo.
    /** @var list<string> $aplicadas */
    $aplicadas = $migrator->table('migrations')
        ->orderBy('id')
        ->pluck('migration')
        ->map(static fn (mixed $name): string => (string) $name) // @phpstan-ignore-line cast.string (`pluck` devuelve `mixed`; la columna es `varchar` y el nombre de una migracion siempre es texto)
        ->values()
        ->all();

    $primera = array_search(MIGRACIONES_DE_RN18[0], $aplicadas, true);

    expect($primera)->toBeInt('La primera migracion de RN-18 no esta aplicada.');

    /** @var int $primera */
    $pasos = \count($aplicadas) - $primera;

    // Y las tres tienen que estar ahi dentro, en su orden: retroceder hasta
    // antes de la primera solo prueba lo que dice si las otras dos van detras.
    expect(array_values(array_intersect($aplicadas, MIGRACIONES_DE_RN18)))->toBe(MIGRACIONES_DE_RN18);

    // Con las migraciones aplicadas, los tres catalogos admiten los valores de
    // RN-18.
    expect(definicionDe('scan_events_chk_result'))->toContain('rejected_out_of_order')
        ->and(definicionDe('scan_events_chk_worked_minutes'))->toContain('rejected_out_of_order')
        ->and(definicionDe('incidents_chk_type'))->toContain('out_of_order_scan');

    [$rolledBack, $rollbackOutput] = Commands::run('migrate:rollback --database='.$name.' --step='.$pasos);

    expect($rolledBack)->toBe(0, $rollbackOutput);

    // Y despues de la vuelta atras, ni rastro del valor nuevo en ninguno de los
    // tres: un `down()` que dejara la restriccion ampliada seria un `down()` que
    // no vuelve a ninguna parte.
    expect(definicionDe('scan_events_chk_result'))->not->toContain('rejected_out_of_order')
        ->and(definicionDe('scan_events_chk_worked_minutes'))->not->toContain('rejected_out_of_order')
        ->and(definicionDe('incidents_chk_type'))->not->toContain('out_of_order_scan')
        // Lo anterior, entero: los ocho resultados y los tres rechazos sin
        // acumulado siguen ahi. Una restriccion que se deshace «de mas» rompe el
        // fichaje del despliegue anterior, que es a lo que se vuelve.
        ->and(definicionDe('scan_events_chk_result'))->toContain('rejected_debounce')
        ->and(definicionDe('scan_events_chk_worked_minutes'))->toContain('rejected_signature')
        ->and(definicionDe('incidents_chk_type'))->toContain('anomalous_pattern')
        // Y validas: `NOT VALID` seguido de `VALIDATE` en el `down()`, no a
        // medias.
        ->and($migrator->table('pg_constraint')->where('conname', 'scan_events_chk_result')->value('convalidated'))->toBeTrue()
        ->and($migrator->table('pg_constraint')->where('conname', 'incidents_chk_type')->value('convalidated'))->toBeTrue();

    [$migrated, $migrateOutput] = Commands::run('migrate --database='.$name);

    expect($migrated)->toBe(0, $migrateOutput)
        ->and(definicionDe('scan_events_chk_result'))->toContain('rejected_out_of_order')
        ->and(definicionDe('scan_events_chk_worked_minutes'))->toContain('rejected_out_of_order')
        ->and(definicionDe('incidents_chk_type'))->toContain('out_of_order_scan')
        ->and($migrator->table('pg_constraint')->where('conname', 'scan_events_chk_worked_minutes')->value('convalidated'))->toBeTrue();
})->group('RN-18', 'RF-PD-10', 'RNF-D-04');
