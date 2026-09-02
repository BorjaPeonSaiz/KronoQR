<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ParallelRequests;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Dos `PATCH /api/v1/compliance-profile` a la vez no pueden perder un cambio
 * ni dejar el perfil en un estado imposible** (RF-PD-07, RL-04).
 *
 * ## Por que el riesgo es mayor que en la configuracion de la instalacion
 *
 * `installation_settings` guarda **una fila por clave**: dos `PATCH` de claves
 * distintas no se pisan aunque no haya candado. `compliance_profiles` guarda
 * **una fila con todas las columnas**, y el repositorio las escribe todas de
 * golpe —tiene que hacerlo: el objeto de valor se valida entero—. Sin candado,
 * dos administradores guardando a la vez producen esto:
 *
 *   A lee (12/9), escribe `min_rest_hours = 10` -> UPDATE con 10/9
 *   B lee (12/9) antes de que A confirme, escribe `max_daily_hours = 8` -> UPDATE con 12/8
 *
 * B no ha tocado el descanso minimo y aun asi **lo devuelve a 12**: el cambio de
 * A desaparece de la fila y su asiento en `audit_log` queda afirmando un cambio
 * que ya no existe. Es la peor combinacion posible en un registro con valor
 * legal: el trail dice una cosa y el umbral vigente dice otra.
 *
 * Lo cierra `pg_advisory_xact_lock(5020001)` tomado **dentro** de la transaccion
 * y antes de leer, con la lectura por `forSiteForWrite()`, que se salta la
 * memoria por peticion.
 *
 * ## Cual de las dos pruebas da la señal, medido
 *
 * Se comprobo retirando el candado y ejecutando las dos. **Las dos fallan, y las
 * dos por su motivo**: la primera con «se esperaba 8 y hay 9» —el escritor que
 * llego el ultimo devolvio la jornada diaria al valor que habia leido, borrando
 * el cambio de otro— y la segunda con «cinco asientos en vez de seis», que es una
 * escritura perdida. Se conservan las dos porque afirman cosas distintas: que
 * **ningun cambio confirmado desaparece de la fila** y que **ningun cambio queda
 * sin asiento**; la primera es la que describe el fallo con palabras que se
 * entienden sin leer el codigo.
 *
 * **Procesos de verdad y no un bucle** (ver `ParallelRequests`): en secuencia
 * dentro de un proceso, estas pruebas pasarian igual sin candado.
 */

uses(CommittedDatabase::class);

/** Seis escritores tocando campos distintos del mismo perfil. */
const ESCRITORES_DE_PERFIL = 6;

beforeEach(function (): void {
    WorkforceFixtures::site();
});

/*
 * Aqui habia un `afterEach` que devolvia el perfil a mano, reescribiendo los diez
 * valores que siembra la migracion. Hacia falta —estas dos pruebas confirman y
 * mutan esa fila, que `CommittedDatabase` conserva como dato de producto— pero
 * era la clase de defensa que solo protege al que se acuerda de escribirla:
 * `SettingsConcurrencyTest` no lo hizo, dejo `ATTENDANCE_MAX_SHIFT_HOURS`
 * cambiado para el resto del proceso y el fallo salio en `Reporting`, a dos
 * modulos de distancia. Y ademas envejecia: eran una copia de los valores de una
 * migracion, asi que al cambiar aquella este `afterEach` restauraria el perfil
 * equivocado sin decir nada.
 *
 * Lo hace ahora `Tests\Support\Database\ProductCatalogBaseline`, que fotografia
 * los catalogos recien migrados y los devuelve a esa foto tras cada prueba que
 * confirma. Sin lista de valores que mantener y sin nada que recordar.
 */

it('no pierde el cambio de nadie aunque seis toquen campos distintos a la vez', function (): void {
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    // Cada escritor mueve UN campo y ninguno toca el del otro. Con la escritura
    // de fila entera y sin candado, el ultimo en confirmar revierte a los cinco
    // anteriores a los valores que leyo.
    $cuerpos = [
        ['min_rest_hours' => 11],
        ['max_daily_hours' => 8],
        ['max_weekly_hours' => 38],
        ['break_required_after_hours' => 5],
        ['week_starts_on' => 7],
        ['retention_years' => 6],
    ];

    $respuestas = ParallelRequests::run(
        ESCRITORES_DE_PERFIL,
        static fn (int $indice): mixed => Api::as($token)
            ->patch('/api/v1/compliance-profile', $cuerpos[$indice]),
    );

    expect(array_map(static fn (array $r): int => $r['status'], $respuestas))
        ->toBe(array_fill(0, ESCRITORES_DE_PERFIL, 200));

    $perfil = DB::table('compliance_profiles')->where('is_default', true)->first();

    // **La invariante.** Los seis cambios estan en la fila: ninguno se ha
    // revertido por haber llegado el ultimo con una lectura vieja.
    expect($perfil?->min_rest_hours)->toBe(11)
        ->and($perfil?->max_daily_hours)->toBe(8)
        ->and($perfil?->max_weekly_hours)->toBe(38)
        ->and($perfil?->break_required_after_hours)->toBe(5)
        ->and($perfil?->week_starts_on)->toBe(7)
        ->and($perfil?->retention_years)->toBe(6);
})->group('RF-PD-07', 'RQ-11');

it('no pierde ni duplica asientos cuando varios cambian el mismo umbral a la vez', function (): void {
    // El candado serializa, no descarta: cada escritor que de verdad cambia el
    // valor deja su asiento, y ninguno queda sin el. Un umbral legal cambiado sin
    // traza es un cambio que nadie puede explicar (regla dura 6).
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
    $valores = range(6, 6 + ESCRITORES_DE_PERFIL - 1);

    // La premisa, comprobada y no supuesta: ninguno de los seis pide el descanso
    // que ya rige. Un `PATCH` que no cambia nada responde `200` y no deja
    // asiento, y la prueba contaria cinco de seis sin que nada estuviera roto.
    expect($valores)->not->toContain(
        DB::table('compliance_profiles')->where('is_default', true)->value('min_rest_hours'),
    );

    $respuestas = ParallelRequests::run(
        ESCRITORES_DE_PERFIL,
        static fn (int $indice): mixed => Api::as($token)
            ->patch('/api/v1/compliance-profile', ['min_rest_hours' => 6 + $indice]),
    );

    expect(array_map(static fn (array $r): int => $r['status'], $respuestas))
        ->toBe(array_fill(0, ESCRITORES_DE_PERFIL, 200));

    $asientos = DB::table('audit_log')
        ->where('action', 'calculation_setting.changed')
        ->where('subject_type', 'compliance_profile')
        ->count();

    expect($asientos)->toBe(ESCRITORES_DE_PERFIL);

    // La fila final es la del ultimo que confirmo, y esta entre los seis valores.
    $final = DB::table('compliance_profiles')->where('is_default', true)->value('min_rest_hours');

    expect($final)->toBeInt()
        ->toBeGreaterThanOrEqual(6)
        ->toBeLessThanOrEqual(6 + ESCRITORES_DE_PERFIL - 1);
})->group('RF-PD-07', 'RL-04');
