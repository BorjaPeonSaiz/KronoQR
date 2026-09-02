<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Una prueba que confirma no puede dejar el producto configurado de otra
 * manera para las que vienen detras.**
 *
 * ## El fallo medido, que es de donde sale esta prueba
 *
 * `CommittedDatabase` no vacia los catalogos de producto —el perfil de
 * cumplimiento, los umbrales de instalacion, los roles— porque son dato del
 * producto y no de la prueba (regla dura 14). Pero **conservar no es restaurar**:
 * las pruebas de concurrencia escriben configuracion de verdad y la confirman, y
 * lo confirmado por otro proceso no lo revierte la transaccion de
 * `RefreshDatabase`.
 *
 * `SettingsConcurrencyTest` dejaba `ATTENDANCE_MAX_SHIFT_HOURS` en el valor del
 * ultimo escritor que confirmara —entre 8 y 13—. Cuando caia en 8, la correccion
 * de nueve horas de `Tests\Feature\Reporting\EmployeeWorkDaysTest` pasaba a
 * clasificarse `anomalous` en vez de `closed` (RN-08) y la prueba fallaba en otro
 * modulo, sin ninguna pista de donde venia. Un fallo de uno de cada seis, y solo
 * al ejecutar las dos suites juntas: se vio primero en la CI y despues no se
 * reproducia en local.
 *
 * ## Por que son dos pruebas y no una
 *
 * La contaminacion entre pruebas **solo se puede afirmar desde la siguiente**: la
 * primera deja el producto tocado y confirmado, y lo que se comprueba es lo que
 * encuentra la segunda. Escribirlo en una sola no diria nada, porque dentro de la
 * misma prueba el estado tocado es el estado esperado.
 *
 * Lo que aqui no cabe: que el vaciado corra **tambien** al terminar y no solo al
 * empezar. Eso es lo que protege a las pruebas de otros ficheros, que son las que
 * de verdad se rompieron, y desde dentro del ciclo de vida de un caso no se puede
 * observar. Vive documentado en {@see \Tests\Support\Database\CommittedDatabase}.
 */

uses(CommittedDatabase::class);

it('deja los catalogos de producto tocados y confirmados', function (): void {
    // Arrange: esto es el montaje de la prueba siguiente, no su comprobacion.
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    // Act
    Api::as($token)
        ->patch('/api/v1/settings', ['settings' => ['ATTENDANCE_MAX_SHIFT_HOURS' => 8]])
        ->assertStatus(200);

    Api::as($token)
        ->patch('/api/v1/compliance-profile', ['min_rest_hours' => 6])
        ->assertStatus(200);

    // Assert: los dos cambios estan escritos. Sin esto, la prueba siguiente
    // pasaria en verde por no haber contaminado nada.
    expect(DB::table('installation_settings')->where('key', 'ATTENDANCE_MAX_SHIFT_HOURS')->value('value'))->toBe('8')
        ->and(DB::table('compliance_profiles')->where('is_default', true)->value('min_rest_hours'))->toBe(6);
})->group('RQ-12', 'RF-PD-01', 'RF-PD-07');

it('encuentra los catalogos como los dejo la migracion, y no como los dejo la prueba anterior', function (): void {
    $perfil = DB::table('compliance_profiles')->where('is_default', true)->first();

    expect(DB::table('installation_settings')->where('key', 'ATTENDANCE_MAX_SHIFT_HOURS')->value('value'))->toBeNull()
        ->and($perfil?->min_rest_hours)->toBe(12)
        ->and($perfil?->max_daily_hours)->toBe(9)
        // Y los catalogos que la restauracion tiene que borrar y reinsertar en el
        // orden que imponen sus claves ajenas siguen enteros: si el orden fallara,
        // el sintoma seria «no existe el rol rrhh» en cualquier prueba posterior.
        ->and(DB::table('roles')->count())->toBe(6)
        ->and(DB::table('permissions')->count())->toBeGreaterThan(0)
        ->and(DB::table('role_has_permissions')->count())->toBeGreaterThan(0);
})->group('RQ-12', 'RF-PD-01', 'RF-PD-07');
