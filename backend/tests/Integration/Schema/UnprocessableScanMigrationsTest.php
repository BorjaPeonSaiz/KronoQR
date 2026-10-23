<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\CommittedDatabase;

/*
 * **Las cuatro migraciones del descarte por el servidor se deshacen y se
 * reaplican** (RN-22, ADR-061, tarea 17.9; skill `/migracion-segura`: toda
 * migracion con su `down()` verificado), ademas del ciclo completo de
 * `MigrationsRoundTripTest`.
 *
 * Y la que guarda constancia **se niega** a deshacerse con descartes del
 * servidor escritos (regla dura 5).
 */

uses(CommittedDatabase::class);

const MIGRACIONES_DEL_DESCARTE = [
    '2026_10_23_100000_allow_discarded_unprocessable_scan_result',
    '2026_10_23_100100_add_server_source_to_discarded_scan_reports',
    '2026_10_23_100200_index_server_discards_by_device',
    '2026_10_23_100300_create_scan_processing_failures_table',
];

function definicionDelDescarte(string $constraint): string
{
    /** @var object{definition: string}|null $fila */
    $fila = DB::connection(config()->string('database.migrations.connection'))
        ->selectOne('SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ?', [$constraint]);

    return $fila === null ? '' : $fila->definition;
}

it('deshace y reaplica las cuatro migraciones del descarte dejando el esquema anterior exacto', function (): void {
    $nombre = config()->string('database.migrations.connection');
    $migrador = DB::connection($nombre);

    /** @var list<string> $aplicadas */
    $aplicadas = $migrador->table('migrations')->orderBy('id')->pluck('migration')
        ->map(static fn (mixed $m): string => is_string($m) ? $m : throw new UnexpectedValueException('migration no es texto'))
        ->values()->all();

    $primera = array_search(MIGRACIONES_DEL_DESCARTE[0], $aplicadas, true);

    expect($primera)->toBeInt('La primera migracion del descarte no esta aplicada.')
        ->and(array_values(array_intersect($aplicadas, MIGRACIONES_DEL_DESCARTE)))->toBe(MIGRACIONES_DEL_DESCARTE);

    /** @var int $primera */
    [$deshecho, $salida] = Commands::run('migrate:rollback --database='.$nombre.' --step='.(\count($aplicadas) - $primera));

    expect($deshecho)->toBe(0, $salida)
        ->and(definicionDelDescarte('scan_events_chk_result'))->not->toContain('discarded_unprocessable')
        ->and(definicionDelDescarte('scan_events_chk_result'))->toContain('rejected_out_of_order')
        ->and(definicionDelDescarte('scan_events_chk_worked_minutes'))->not->toContain('discarded_unprocessable')
        ->and(definicionDelDescarte('scan_events_chk_discarded_has_no_shift_entry'))->toBe('')
        ->and(definicionDelDescarte('discarded_scan_reports_chk_source'))->toBe('')
        ->and(Schema::connection($nombre)->hasColumn('discarded_scan_reports', 'source'))->toBeFalse()
        ->and(Schema::connection($nombre)->hasTable('scan_processing_failures'))->toBeFalse()
        ->and($migrador->table('pg_constraint')->where('conname', 'scan_events_chk_result')->value('convalidated'))->toBeTrue();

    [$migrado, $salidaMigrar] = Commands::run('migrate --database='.$nombre);

    expect($migrado)->toBe(0, $salidaMigrar)
        ->and(definicionDelDescarte('scan_events_chk_result'))->toContain('discarded_unprocessable')
        ->and(definicionDelDescarte('scan_events_chk_discarded_has_no_shift_entry'))->toContain('discarded_unprocessable')
        ->and(definicionDelDescarte('discarded_scan_reports_chk_source'))->toContain('server')
        ->and(Schema::connection($nombre)->hasTable('scan_processing_failures'))->toBeTrue()
        ->and($migrador->table('pg_constraint')->where('conname', 'discarded_scan_reports_chk_source')->value('convalidated'))->toBeTrue()
        ->and($migrador->table('pg_indexes')->where('indexname', 'discarded_scan_reports_server_device_recorded_at_index')->exists())->toBeTrue();
})->group('RN-22', 'RF-PD-10', 'RNF-D-04');

it('se niega a deshacer las columnas del origen con descartes del servidor escritos', function (): void {
    $escenario = AttendanceFixtures::scenario();

    DB::table('discarded_scan_reports')->insert([
        'scan_id' => Str::uuid7()->toString(),
        'device_id' => $escenario['device'],
        'origin' => 'qr_kiosk',
        'occurred_at' => '2026-03-14 07:00:00+00',
        'discarded_at' => '2026-03-14 08:00:00+00',
        'recorded_at' => '2026-03-14 08:00:00+00',
        'http_status' => 422,
        'problem_type' => 'urn:kronoqr:problem:scan-discarded',
        'owner_employee_id' => null,
        'attribution' => 'none',
        'credential_issued_at' => null,
        'already_recorded' => false,
        'source' => 'server',
        'failed_attempts' => 10,
        'first_failed_at' => '2026-03-14 07:00:00+00',
    ]);

    /** @var Migration $migracion */
    $migracion = require database_path('migrations/'.MIGRACIONES_DEL_DESCARTE[1].'.php');

    $deshacer = static function () use ($migracion): void {
        if (! method_exists($migracion, 'down')) {
            throw new LogicException('La migracion no tiene down().');
        }

        $migracion->down();
    };

    expect(static function () use ($deshacer): void {
        DB::transaction($deshacer);
    })->toThrow(RuntimeException::class, 'regla dura 5');

    // Y la columna sigue: no se toco nada antes de negarse.
    expect(Schema::hasColumn('discarded_scan_reports', 'source'))->toBeTrue();
})->group('RN-22', 'RL-04');
