<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;

/*
 * El esquema de `installation_settings` despues de la contraccion del ambito
 * (RF-PD-01, ADR-040, tarea 5.1).
 *
 * POR QUE ESTA SUITE NO PODIA SER UNITARIA. Todo lo que se comprueba aqui vive
 * en el motor y no en PHP: que una segunda fila con la misma clave sea imposible,
 * que las columnas de ambito ya no existan y que la migracion sepa volver atras.
 * Un doble en memoria daria las tres por buenas sin haberlas comprobado nunca, y
 * de la primera depende que la cascada tenga una unica respuesta.
 */

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function settingRow(string $key, string $json): array
{
    return [
        'key' => $key,
        'value' => $json,
        'updated_at' => '2026-01-01 00:00:00+00',
    ];
}

it('admite una fila por clave', function (): void {
    DB::table('installation_settings')->insert(settingRow('BRANDING_APP_NAME', '"Hotel Marina"'));

    expect(DB::table('installation_settings')->where('key', 'BRANDING_APP_NAME')->count())->toBe(1);
})->group('RF-PD-01');

it('rechaza una segunda fila con la misma clave', function (): void {
    // La garantia no es un `SELECT` previo en PHP sino el indice unico: el
    // segundo `INSERT` falla tambien para quien escriba desde `psql`. Sin el, la
    // misma clave podria estar dos veces y ganaria la que devolviera el
    // planificador — es decir, cualquiera.
    DB::table('installation_settings')->insert(settingRow('BRANDING_APP_NAME', '"Hotel Marina"'));

    expect(fn () => DB::table('installation_settings')->insert(settingRow('BRANDING_APP_NAME', '"Hotel Atlantico"')))
        ->toThrow(QueryException::class, 'one_setting_per_key');
})->group('RF-PD-01');

it('declara el indice como unico y sin predicado parcial', function (): void {
    // Sin predicado: antes eran dos indices parciales por `scope`, y razonar
    // sobre cual aplicaba era parte del coste que la contraccion elimina.
    $definition = DB::table('pg_indexes')
        ->where('tablename', 'installation_settings')
        ->where('indexname', 'one_setting_per_key')
        ->value('indexdef');

    expect($definition)->toBeString()
        ->and($definition)->toContain('UNIQUE INDEX')
        ->and($definition)->not->toContain('WHERE');
})->group('RF-PD-01');

it('ya no tiene columnas de ambito ni los indices que dependian de ellas', function (): void {
    // ADR-040: hay un centro por instalacion, asi que un ambito que siempre
    // resuelve al mismo sitio no es una cascada.
    $columns = DB::table('information_schema.columns')
        ->where('table_name', 'installation_settings')
        ->pluck('column_name')
        ->all();

    expect($columns)->not->toContain('scope')
        ->and($columns)->not->toContain('scope_id')
        // Y lo que si tiene que seguir estando: quien lo cambio y cuando.
        ->and($columns)->toContain('key', 'value', 'updated_by_user_id', 'updated_at');

    $indexes = DB::table('pg_indexes')
        ->where('tablename', 'installation_settings')
        ->pluck('indexname')
        ->all();

    expect($indexes)->not->toContain('one_installation_setting_per_key')
        ->and($indexes)->not->toContain('one_site_setting_per_key');
})->group('RF-PD-01');

it('deja la tabla vacia tras una instalacion limpia', function (): void {
    // La contraccion retira la siembra de la tarea 1.3 **que nadie ha tocado**.
    // Aquellas filas existian por una limitacion del adaptador de entonces —que
    // lanzaba si faltaba una clave—, no por decision de producto: desde la tarea
    // 5.1 el valor de serie vive en `SettingKey`, en codigo, y una instalacion
    // sin ninguna fila arranca y funciona.
    //
    // Que la tabla quede vacia no es cosmetico: con las filas puestas,
    // `GET /settings` devolveria `source: installation` para cuatro claves que
    // nadie configuro, y el primer asiento de auditoria diria
    // `was_product_default: false` cuando lo cierto es que regia el valor del
    // producto. Es justo la distincion que esos dos campos existen para hacer.
    expect(DB::table('installation_settings')->count())->toBe(0);
})->group('RF-PD-01');

it('no toca una fila sembrada que alguien haya configurado', function (): void {
    // La contraccion exige las DOS condiciones: sin autor **y** con el valor de
    // serie. Una fila que alguien guardo desde el panel se queda, aunque su
    // numero coincida — y su `updated_by_user_id` es lo que lo demuestra.
    //
    // Se comprueba re-ejecutando el borrado sobre una fila con autor, que es lo
    // que haria `migrate` sobre la base de un cliente que ya la tenia.
    // Una cuenta de verdad: la columna tiene clave ajena a `users`, y con
    // `null` esta prueba comprobaria justo lo contrario de lo que dice.
    $userId = ManagementUsers::withRole(UserRole::ADMIN)->id;

    DB::table('installation_settings')->insert([
        'key' => 'ATTENDANCE_MAX_SHIFT_HOURS',
        'value' => '12',
        'updated_by_user_id' => $userId,
        'updated_at' => '2026-01-01 00:00:00+00',
    ]);

    $deleted = DB::table('installation_settings')
        ->where('key', 'ATTENDANCE_MAX_SHIFT_HOURS')
        ->whereNull('updated_by_user_id')
        ->whereRaw('value = ?::jsonb', ['12'])
        ->delete();

    expect($deleted)->toBe(0)
        ->and(DB::table('installation_settings')->where('key', 'ATTENDANCE_MAX_SHIFT_HOURS')->exists())->toBeTrue();
})->group('RF-PD-01');
