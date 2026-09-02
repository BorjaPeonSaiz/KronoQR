<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\SetupStep;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Lo que sostiene el asistente **en el esquema** (RF-PD-03, tarea 5.5).
 *
 * Las afirmaciones de este fichero no se pueden hacer con dobles: son sobre
 * PostgreSQL. La regla de negocio esta probada en unitarias; aqui se comprueba
 * que la ultima linea de defensa existe y que dice lo mismo (doc 02 §3.2).
 */

uses(RefreshDatabase::class);

it('admite un solo estado por paso, sin comprobacion previa', function (): void {
    // La clave primaria es lo que hace idempotente el `PUT` del contrato: dos
    // pestañas del panel marcando el mismo paso no dejan dos filas.
    DB::table('setup_progress')->insert([
        'step' => SetupStep::LICENSE->value,
        'state' => 'skipped',
        'recorded_at' => '2026-09-02 08:00:00+00',
        'recorded_by_user_id' => null,
    ]);

    expect(fn () => DB::table('setup_progress')->insert([
        'step' => SetupStep::LICENSE->value,
        'state' => 'completed',
        'recorded_at' => '2026-09-02 08:01:00+00',
        'recorded_by_user_id' => null,
    ]))->toThrow(QueryException::class);
})->group('RF-PD-03');

it('no admite un paso que el producto no conoce', function (): void {
    // El `CHECK` se escribe ademas del enum de PHP: una fila con un paso
    // inventado —por una restauracion antigua o por una version que ya no
    // existe— dejaria el asistente calculando su estado a partir de algo que no
    // sabe interpretar.
    expect(fn () => DB::table('setup_progress')->insert([
        'step' => 'impresora',
        'state' => 'completed',
        'recorded_at' => '2026-09-02 08:00:00+00',
        'recorded_by_user_id' => null,
    ]))->toThrow(QueryException::class);
})->group('RF-PD-03');

it('no admite `pending` como estado guardado', function (): void {
    // `pending` es la AUSENCIA de fila. Dos formas de decir lo mismo en una tabla
    // acaban siempre en una consulta que trata una y olvida la otra.
    expect(fn () => DB::table('setup_progress')->insert([
        'step' => SetupStep::LICENSE->value,
        'state' => 'pending',
        'recorded_at' => '2026-09-02 08:00:00+00',
        'recorded_by_user_id' => null,
    ]))->toThrow(QueryException::class);
})->group('RF-PD-03');

it('no admite un cierre del asistente «omitido»', function (): void {
    expect(fn () => DB::table('setup_progress')->insert([
        'step' => SetupStep::COMPLETION_KEY,
        'state' => 'skipped',
        'recorded_at' => '2026-09-02 08:00:00+00',
        'recorded_by_user_id' => null,
    ]))->toThrow(QueryException::class);
})->group('RF-PD-03');

it('declara en el CHECK exactamente los pasos del catalogo, mas el cierre', function (): void {
    // El enum de PHP y el `CHECK` de la migracion son dos copias de la misma
    // lista. Si se separaran, un paso nuevo pasaria las pruebas de dominio y
    // fallaria al escribirse en la instalacion del cliente.
    /** @var object{definition: string}|null $constraint */
    $constraint = DB::selectOne(<<<'SQL'
        SELECT pg_get_constraintdef(oid) AS definition
          FROM pg_constraint
         WHERE conname = 'setup_progress_chk_step'
    SQL);

    expect($constraint)->not->toBeNull();

    $definition = (string) $constraint?->definition;

    foreach (SetupStep::cases() as $step) {
        expect($definition)->toContain("'".$step->value."'");
    }

    expect($definition)->toContain("'".SetupStep::COMPLETION_KEY."'");
})->group('RF-PD-03');

it('conserva la marca del paso si la cuenta que lo resolvio desaparece', function (): void {
    // El hecho de que el paso se resolvio no se pierde con la persona. Este dato
    // NO es el registro legal de quien hizo que —eso es `audit_log`, solo-append
    // y encadenado por hash— sino lo que permite decir «esto lo dejaste tu».
    $userId = DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid7(),
        'name' => 'Cuenta de prueba',
        'email' => 'prueba-setup@kronoqr.test',
        'password' => 'no-importa',
        'locale' => 'es',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('setup_progress')->insert([
        'step' => SetupStep::LICENSE->value,
        'state' => 'skipped',
        'recorded_at' => '2026-09-02 08:00:00+00',
        'recorded_by_user_id' => $userId,
    ]);

    DB::table('users')->where('id', $userId)->delete();

    $row = DB::table('setup_progress')->where('step', SetupStep::LICENSE->value)->first();

    expect($row)->not->toBeNull()
        ->and($row?->state)->toBe('skipped')
        ->and($row?->recorded_by_user_id)->toBeNull();
})->group('RF-PD-03');

it('sigue impidiendo un segundo centro con el indice de fila unica', function (): void {
    // ADR-040. El alta del asistente se apoya en `sites_single_row_uidx` y no en
    // una consulta previa: entre el `SELECT` y el `INSERT` cabe otra puesta en
    // marcha, y el indice es lo que hace imposible el segundo centro tambien
    // bajo concurrencia.
    WorkforceFixtures::site();

    expect(fn () => DB::table('sites')->insert([
        'name' => 'Hotel Atlantico',
        'timezone' => 'Atlantic/Canary',
    ]))->toThrow(QueryException::class);
})->group('RF-PD-03', 'RN-05');
