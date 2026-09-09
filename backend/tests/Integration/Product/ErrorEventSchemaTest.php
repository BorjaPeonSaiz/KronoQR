<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Database\RefreshDatabase;

/*
 * El esquema de `error_events` dice lo mismo que el codigo, y su `down()` existe
 * de verdad (RF-PD-15, RNF-D-04, «una migracion cuyo `down()` no se ha probado
 * no tiene `down()`»).
 *
 * ## Por que los `CHECK` se comprueban contra el motor
 *
 * La migracion los compone de {@see ErrorLevel} y {@see ErrorSource}, asi que en
 * teoria no pueden separarse. Lo que esta prueba anade es que **lo que la base
 * de datos TIENE** sea eso: una migracion aplicada a medias o un `ALTER` hecho a
 * mano en la instalacion de un cliente no se ven de otra forma.
 */

uses(RefreshDatabase::class);

/** La definicion que PostgreSQL guarda de una restriccion. */
function restriccionDeErrorEvents(string $nombre): string
{
    $filas = DB::select(
        'SELECT pg_get_constraintdef(oid) AS definicion FROM pg_constraint WHERE conname = ?',
        [$nombre],
    );

    $definicion = $filas === [] ? null : ($filas[0]->definicion ?? null);

    return is_string($definicion) ? $definicion : '';
}

it('los CHECK de level y source admiten exactamente los valores de los enums', function (): void {
    foreach ([
        'error_events_chk_level' => ErrorLevel::names(),
        'error_events_chk_source' => ErrorSource::names(),
    ] as $restriccion => $esperados) {
        $definicion = restriccionDeErrorEvents($restriccion);

        expect($definicion)->not->toBe('', 'No existe la restriccion `'.$restriccion.'`.');

        preg_match_all("/'([a-z_]+)'/", $definicion, $coincidencias);

        $enElEsquema = array_values(array_unique($coincidencias[1]));

        sort($enElEsquema);
        sort($esperados);

        expect($enElEsquema)->toBe($esperados);
    }
})->group('RF-PD-15');

it('la huella es unica: es lo que hace correcto el ON CONFLICT', function (): void {
    // Sin el `UNIQUE`, cientos de procesos escribiendo el mismo fallo a la vez
    // dejarian cientos de filas, que es justo el ruido que la agrupacion evita.
    $huella = str_repeat('a', 64);

    DB::table('error_events')->insert(filaDeErrorEvents(['fingerprint' => $huella]));

    expect(static fn () => DB::table('error_events')->insert(filaDeErrorEvents(['fingerprint' => $huella])))
        ->toThrow(QueryException::class);
})->group('RF-PD-15');

it('un grupo no puede afirmar que ocurrio cero veces', function (): void {
    expect(static fn () => DB::table('error_events')->insert(filaDeErrorEvents(['occurrences' => 0])))
        ->toThrow(QueryException::class);
})->group('RF-PD-15');

it('no admite un autor de resolucion sin instante', function (): void {
    // Un «resuelto por nadie» -o un autor sin cuando- es un estado que nadie
    // sabria interpretar seis meses despues.
    expect(static fn () => DB::table('error_events')->insert(filaDeErrorEvents([
        'resolved_by_user_id' => 1,
        'resolved_at' => null,
    ])))->toThrow(QueryException::class);
})->group('RF-PD-15');

it('el rol de la aplicacion puede borrar aqui, al contrario que en audit_log', function (): void {
    // Regla dura 6, al reves: esto NO es auditoria. Son datos tecnicos con 90
    // dias de vida, y `product:errors:prune` necesita `DELETE`.
    DB::table('error_events')->insert(filaDeErrorEvents());

    expect(DB::table('error_events')->delete())->toBe(1);

    expect(static fn () => DB::table('audit_log')->delete())->toThrow(QueryException::class);
})->group('RF-PD-15', 'RS-07');

it('la migracion se deshace y se reaplica dejando la tabla igual', function (): void {
    // «Una migracion cuyo `down()` no se ha probado no tiene `down()`».
    $migracion = require database_path('migrations/2026_09_13_100000_create_error_events_table.php');

    $antes = Schema::connection('pgsql_migrator')->getColumnListing('error_events');

    expect($antes)->not->toBeEmpty();

    DB::setDefaultConnection('pgsql_migrator');

    try {
        $migracion->down();

        expect(Schema::connection('pgsql_migrator')->hasTable('error_events'))->toBeFalse();

        $migracion->up();
    } finally {
        DB::setDefaultConnection('pgsql');
    }

    expect(Schema::connection('pgsql_migrator')->getColumnListing('error_events'))->toBe($antes);
})->group('RF-PD-15');

/**
 * Una fila valida de `error_events`, con solo lo obligatorio.
 *
 * @param  array<string, mixed>  $columnas
 * @return array<string, mixed>
 */
function filaDeErrorEvents(array $columnas = []): array
{
    $ahora = now()->toDateTimeString('microsecond');

    return array_merge([
        'fingerprint' => bin2hex(random_bytes(32)),
        'level' => 'error',
        'source' => 'api',
        'message' => 'algo fallo',
        'context' => '{}',
        'app_version' => '2.2.0',
        'occurrences' => 1,
        'first_seen_at' => $ahora,
        'last_seen_at' => $ahora,
    ], $columnas);
}
