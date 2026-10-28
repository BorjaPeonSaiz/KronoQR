<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\CommittedDatabase;

/*
 * **La migracion de `rejected_suspended` se deshace y se reaplica** (RN-25;
 * skill `/migracion-segura`: toda migracion con su `down()` verificado), ademas
 * del ciclo completo de `MigrationsRoundTripTest`.
 *
 * Y **se niega** a deshacerse con rechazos de personas suspendidas escritos: el
 * log no se reescribe (regla dura 5).
 *
 * `CommittedDatabase` por lo mismo que `OutOfOrderScanMigrationsTest`: el
 * `migrate:rollback` corre con el rol de migracion, en otra conexion.
 */

uses(CommittedDatabase::class);

const SUSPENDED_SCAN_RESULT_MIGRATION = '2026_10_27_100000_allow_rejected_suspended_scan_result';

function definicionDeRechazoSuspendido(string $constraint): string
{
    /** @var object{definition: string}|null $fila */
    $fila = DB::connection(config()->string('database.migrations.connection'))
        ->selectOne('SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ?', [$constraint]);

    return $fila === null ? '' : $fila->definition;
}

it('deshace y reaplica la migracion de rejected_suspended dejando los CHECK anteriores exactos', function (): void {
    $nombre = config()->string('database.migrations.connection');
    $migrador = DB::connection($nombre);

    /** @var list<string> $aplicadas */
    $aplicadas = $migrador->table('migrations')->orderBy('id')->pluck('migration')
        ->map(static fn (mixed $m): string => is_string($m) ? $m : throw new UnexpectedValueException('migration no es texto'))
        ->values()->all();

    $posicion = array_search(SUSPENDED_SCAN_RESULT_MIGRATION, $aplicadas, true);

    expect($posicion)->toBeInt('La migracion de rejected_suspended no esta aplicada.')
        ->and(definicionDeRechazoSuspendido('scan_events_chk_result'))->toContain('rejected_suspended')
        ->and(definicionDeRechazoSuspendido('scan_events_chk_worked_minutes'))->toContain('rejected_suspended');

    // Se calcula y no se fija en 1: una migracion posterior desplazaria el paso.
    /** @var int $posicion */
    [$deshecho, $salida] = Commands::run('migrate:rollback --database='.$nombre.' --step='.(\count($aplicadas) - $posicion));

    expect($deshecho)->toBe(0, $salida)
        ->and(definicionDeRechazoSuspendido('scan_events_chk_result'))->not->toContain('rejected_suspended')
        ->and(definicionDeRechazoSuspendido('scan_events_chk_worked_minutes'))->not->toContain('rejected_suspended')
        // Lo anterior, entero: un `down()` que deshace de mas rompe el fichaje
        // de la version a la que se vuelve.
        ->and(definicionDeRechazoSuspendido('scan_events_chk_result'))->toContain('discarded_unprocessable')
        ->and(definicionDeRechazoSuspendido('scan_events_chk_worked_minutes'))->toContain('discarded_unprocessable')
        ->and($migrador->table('pg_constraint')->where('conname', 'scan_events_chk_result')->value('convalidated'))->toBeTrue()
        ->and($migrador->table('pg_constraint')->where('conname', 'scan_events_chk_worked_minutes')->value('convalidated'))->toBeTrue();

    [$migrado, $salidaMigrar] = Commands::run('migrate --database='.$nombre);

    expect($migrado)->toBe(0, $salidaMigrar)
        ->and(definicionDeRechazoSuspendido('scan_events_chk_result'))->toContain('rejected_suspended')
        ->and(definicionDeRechazoSuspendido('scan_events_chk_worked_minutes'))->toContain('rejected_suspended')
        ->and($migrador->table('pg_constraint')->where('conname', 'scan_events_chk_result')->value('convalidated'))->toBeTrue();
})->group('RN-25', 'RF-PD-10', 'RNF-D-04');

it('se niega a deshacerse con rechazos de personas suspendidas escritos', function (): void {
    $escenario = AttendanceFixtures::scenario();

    DB::table('scan_events')->insert([
        'scan_id' => Str::uuid7()->toString(),
        'device_id' => $escenario['device'],
        'employee_id' => null,
        'occurred_at' => '2026-03-14 07:00:00+00',
        'recorded_at' => '2026-03-14 07:00:01+00',
        'origin' => 'qr_kiosk',
        'intent' => 'auto',
        'result' => 'rejected_suspended',
        'flagged_for_review' => false,
        'worked_minutes' => null,
        'client_meta' => '{}',
    ]);

    /** @var Migration $migracion */
    $migracion = require database_path('migrations/'.SUSPENDED_SCAN_RESULT_MIGRATION.'.php');

    // Con el rol de migracion, como en un `migrate:rollback`: la comprobacion va
    // despues del primer ALTER TABLE, y el rol de la aplicacion no puede hacerlo.
    $porDefecto = DB::getDefaultConnection();
    DB::setDefaultConnection(config()->string('database.migrations.connection'));

    try {
        expect(static function () use ($migracion): void {
            if (! method_exists($migracion, 'down')) {
                throw new LogicException('La migracion no tiene down().');
            }

            $migracion->down();
        })->toThrow(RuntimeException::class, 'regla dura 5');
    } finally {
        DB::setDefaultConnection($porDefecto);
    }

    // Y las dos restricciones siguen admitiendo el valor: el DROP anterior a la
    // comprobacion se deshizo con la transaccion.
    expect(definicionDeRechazoSuspendido('scan_events_chk_result'))->toContain('rejected_suspended')
        ->and(definicionDeRechazoSuspendido('scan_events_chk_worked_minutes'))->toContain('rejected_suspended');
})->group('RN-25', 'RL-04');
