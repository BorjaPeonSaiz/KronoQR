<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El esquema de la telemetria de salud de `devices` (RF-PA-07, tarea 3.3,
 * migracion `2026_09_16_100000`).
 *
 * POR QUE ESTA SUITE NO PODIA SER UNITARIA. Todo lo que se comprueba aqui vive
 * en el motor y no en PHP: que las tres columnas existan con su tipo, que
 * admitan `NULL` —que es el estado normal de una tablet que no informa de su
 * bateria— y que el `CHECK` del nivel rechace un porcentaje imposible **tambien
 * para quien escriba desde `psql`**. Un doble en memoria daria las tres por
 * buenas sin haberlas comprobado nunca.
 *
 * LA INVARIANTE QUE PROTEGE EL `CHECK`. `SMALLINT` admite hasta 32 767. El
 * contrato y el `FormRequest` acotan 0..100 en el borde, pero un `UPDATE` a mano
 * durante una reparacion, o un adaptador futuro que se salte el caso de uso,
 * dejarian un `-1` o un `300` en la columna, y de ahi saldrian a la metrica
 * `kiosk_battery_level` y a la columna del panel sin que nada los parase.
 */

uses(RefreshDatabase::class);

/**
 * Una fila de `devices` minima, con la telemetria que se le indique.
 *
 * No pasa por el caso de uso a proposito: lo que se comprueba es el motor, y
 * hacerlo por la API taparia con la validacion del borde justo lo que esta
 * prueba quiere ver fallar.
 *
 * @param  array<string, mixed>  $telemetry
 */
function deviceWithTelemetry(array $telemetry, string $name = 'Recepcion'): int
{
    return DB::table('devices')->insertGetId([
        'uuid' => (string) Str::uuid7(),
        'site_id' => WorkforceFixtures::site(),
        'name' => $name,
        'status' => 'active',
        'pending_queue_size' => 0,
        'created_at' => '2026-09-16 08:00:00+00',
        'updated_at' => '2026-09-16 08:00:00+00',
        ...$telemetry,
    ]);
}

it('declara las tres columnas de telemetria y las tres admiten NULL', function (): void {
    // `NULL` NO ES CERO y por eso importa que se admita: la Battery Status API
    // solo la ofrece Chrome en Android, y una tablet que no informa no es una
    // tablet averiada. Un `NOT NULL DEFAULT 0` habria puesto en aviso a la flota
    // entera el dia del despliegue.
    $columns = DB::table('information_schema.columns')
        ->where('table_name', 'devices')
        ->whereIn('column_name', ['battery_level', 'battery_charging', 'oldest_pending_at'])
        ->get(['column_name', 'data_type', 'is_nullable', 'column_default'])
        ->keyBy('column_name');

    expect($columns)->toHaveCount(3);

    /** @var object{data_type: string, is_nullable: string, column_default: string|null} $level */
    $level = $columns['battery_level'];
    /** @var object{data_type: string, is_nullable: string, column_default: string|null} $charging */
    $charging = $columns['battery_charging'];
    /** @var object{data_type: string, is_nullable: string, column_default: string|null} $oldest */
    $oldest = $columns['oldest_pending_at'];

    expect($level->data_type)->toBe('smallint')
        ->and($charging->data_type)->toBe('boolean')
        // Regla dura 3: todo instante es `TIMESTAMPTZ`.
        ->and($oldest->data_type)->toBe('timestamp with time zone');

    foreach ([$level, $charging, $oldest] as $column) {
        expect($column->is_nullable)->toBe('YES')
            // Sin valor de serie: una cifra plausible y falsa es peor que un hueco.
            ->and($column->column_default)->toBeNull();
    }
})->group('RF-PA-07');

it('acepta un nivel de bateria dentro de rango, los dos extremos incluidos', function (int $level): void {
    $id = deviceWithTelemetry(['battery_level' => $level, 'battery_charging' => false], 'Quiosco '.$level);

    expect(DB::table('devices')->where('id', $id)->value('battery_level'))->toBe($level);
})->with([
    'vacia del todo' => [0],
    'a la mitad' => [50],
    'llena' => [100],
])->group('RF-PA-07');

it('rechaza en el motor un nivel de bateria imposible', function (int $level): void {
    // La garantia no es el `FormRequest` sino el `CHECK`: el segundo `INSERT`
    // falla tambien para quien escriba desde `psql` durante una reparacion.
    expect(fn (): int => deviceWithTelemetry(['battery_level' => $level], 'Quiosco '.$level))
        ->toThrow(QueryException::class, 'devices_chk_battery_level_range');
})->with([
    'negativa' => [-1],
    'por encima de cien' => [300],
])->group('RF-PA-07');

it('deja la telemetria vacia en un quiosco que nunca ha latido', function (): void {
    // Es el estado de una tablet recien vinculada, y el que el contrato declara
    // `nullable` en `Device`: no se inventa un cero ni una fecha.
    $id = deviceWithTelemetry([]);

    /** @var object{battery_level: int|null, battery_charging: bool|null, oldest_pending_at: string|null} $row */
    $row = DB::table('devices')->where('id', $id)->first(['battery_level', 'battery_charging', 'oldest_pending_at']);

    expect($row->battery_level)->toBeNull()
        ->and($row->battery_charging)->toBeNull()
        ->and($row->oldest_pending_at)->toBeNull();
})->group('RF-PA-07');
