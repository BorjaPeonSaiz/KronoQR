<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\SupportScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\SupportGrants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **EL FABRICANTE NO LEE EL REGISTRO DE AUSENCIAS DEL CLIENTE**
 * (regla dura 16, ADR-020, RL-19, RF-GP-04, art. 9 del RGPD).
 *
 * ## Por que esta prueba existe y por que es la mas importante de este fichero
 *
 * `/absences` es la unica pantalla de `Workforce` que sirve **dato de salud**.
 * `type: sick_leave` dice que una persona concreta esta de baja medica, y la
 * `note` puede llevar el motivo: eso es categoria especial del art. 9 del RGPD,
 * no «un campo mas de la ficha».
 *
 * Y hasta el cierre de la Fase 3 un token del fabricante lo leia entero. No por
 * un fallo aislado, sino porque **las dos mitades de la autorizacion decian que
 * si**:
 *
 *   1. Las dos rutas de lectura cuelgan de `ability:employees:read`, y
 *      `SupportScope::ReadOnly` concede exactamente ese ambito. El middleware
 *      abre.
 *   2. `SupportScope::actsAs()` devuelve `ADMIN`, y `AbsencePolicy` era la unica
 *      policy que alcanza dato sensible sin preguntar `isSupportActor()`. La
 *      policy abre.
 *
 * Ninguna de las dos estaba mal por separado: el ambito tiene que abrir —es lo
 * que hace util al alcance `read_only`— y el rol tiene que ser `admin` —o la
 * concesion no veria ni una jornada—. Lo que faltaba era la tercera pregunta,
 * la que no se puede expresar ni con roles ni con ambitos: **quien** es el que
 * actua.
 *
 * ## Los tres alcances, aunque dos se queden en el middleware
 *
 * `diagnostics` y `configuration` no llevan `employees:read` y no pasan de ahi.
 * Se prueban igual, por lo mismo que en `DataExportAuthorizationTest`: lo
 * que importa no es **donde** se para cada uno, sino que **ninguno pasa**. El
 * dia que alguien amplie la lista de ambitos de un alcance, esta prueba tiene
 * que seguir siendo la que lo pare.
 *
 * ## Y sin asiento de divulgacion
 *
 * El `403` es la mitad. La otra es que no quede un `personal_data.accessed` con
 * el fabricante como actor: si lo hubiera, significaria que la consulta llego a
 * ejecutarse y que alguien leyo las filas antes de que nada lo impidiera.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
});

/**
 * Una baja medica con nota, escrita con el constructor de consultas.
 *
 * **Con `sick_leave` y con nota a proposito**: es el peor caso que la pantalla
 * puede servir —dato del art. 9 mas texto libre que puede llevar un
 * diagnostico— y es el que tiene que quedarse dentro.
 */
function bajaMedicaConNota(): string
{
    $siteId = WorkforceFixtures::onlySiteId();
    $employeeUuid = WorkforceFixtures::employee($siteId);

    /** @var int|string|null $employeeId */
    $employeeId = DB::table('employees')->where('uuid', $employeeUuid)->value('id');

    $uuid = Str::uuid7()->toString();

    DB::table('absences')->insert([
        'uuid' => $uuid,
        'employee_id' => \is_numeric($employeeId) ? (int) $employeeId : 0,
        'type' => 'sick_leave',
        'starts_on' => '2026-03-02',
        'ends_on' => '2026-03-06',
        'note' => 'Parte de baja por lumbalgia',
        'status' => 'active',
        'version' => 1,
        'created_at' => '2026-03-01T08:00:00+00:00',
    ]);

    return $uuid;
}

/** Ninguna lectura rechazada puede dejar constancia de una divulgacion que no ocurrio. */
function nadieHaDivulgadoAusencias(): void
{
    expect(DB::table('audit_log')->where('action', 'personal_data.accessed')->count())->toBe(0);
}

it('rechaza los TRES alcances de soporte en las dos rutas de lectura', function (SupportScope $alcance): void {
    $uuid = bajaMedicaConNota();
    $token = SupportGrants::tokenFor($alcance);

    Api::as($token)->get('/api/v1/absences?from=2026-03-01&to=2026-03-31')->assertStatus(403);
    Api::as($token)->get('/api/v1/absences/'.$uuid)->assertStatus(403);

    nadieHaDivulgadoAusencias();
})->with([
    // Se para en el middleware: no lleva `employees:read`.
    'diagnostics' => [SupportScope::Diagnostics],
    // **PASA EL MIDDLEWARE.** Lo unico que lo para es `AbsencePolicy`.
    'read_only' => [SupportScope::ReadOnly],
    // Se para en el middleware: lleva `settings:*`, no plantilla.
    'configuration' => [SupportScope::Configuration],
])->group('RF-GP-04', 'RF-PD-11', 'RL-19', 'ADR-020');

it('rechaza un token de soporte al que alguien le hubiera puesto el ambito a mano', function (): void {
    /*
     * La otra mitad de la regla dura 18: **la policy cierra aunque el ambito
     * abra**. Con los ambitos de serie solo `read_only` llega a la policy, asi
     * que sin este caso un cambio en la lista de alcances podria dejar a los
     * otros dos apoyados unicamente en el middleware sin que nada lo dijera.
     */
    $uuid = bajaMedicaConNota();
    $token = SupportGrants::tokenWithAbilities(['employees:read'], SupportScope::Diagnostics);

    Api::as($token)->get('/api/v1/absences?from=2026-03-01&to=2026-03-31')->assertStatus(403);
    Api::as($token)->get('/api/v1/absences/'.$uuid)->assertStatus(403);

    nadieHaDivulgadoAusencias();
})->group('RF-GP-04', 'RF-PD-11', 'RL-19', 'ADR-020');

it('tampoco deja que un actor de soporte escriba una ausencia', function (SupportScope $alcance): void {
    // Las cuatro de escritura se quedan hoy en el middleware —ningun alcance
    // concede `employees:*`—, pero la policy las cierra ademas: que el
    // fabricante no registre, corrija ni anule una baja medica del cliente no
    // puede depender en exclusiva de la lista de ambitos de otro modulo.
    $uuid = bajaMedicaConNota();
    $token = SupportGrants::tokenFor($alcance);

    Api::as($token)->post('/api/v1/absences')->assertStatus(403);
    Api::as($token)->patch('/api/v1/absences/'.$uuid)->assertStatus(403);
    Api::as($token)->post('/api/v1/absences/'.$uuid.'/void')->assertStatus(403);
    Api::as($token)->post('/api/v1/absences/import')->assertStatus(403);

    // Ni una version nueva, ni una anulacion, ni un asiento: la ausencia queda
    // exactamente como estaba.
    expect(DB::table('absences')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'like', 'absence.%')->count())->toBe(0);
    nadieHaDivulgadoAusencias();
})->with([
    'diagnostics' => [SupportScope::Diagnostics],
    'read_only' => [SupportScope::ReadOnly],
    'configuration' => [SupportScope::Configuration],
])->group('RF-GP-04', 'RF-PD-11', 'RL-19', 'ADR-020');

it('el administrador del cliente sigue leyendo sus ausencias con la nota', function (): void {
    // La otra mitad de la prueba negativa, y la que impide «arreglarlo» cerrando
    // la pantalla: quien gestiona la plantilla del hotel tiene que seguir viendo
    // la baja y su nota, que es para lo que la pantalla existe (RF-GP-04).
    $uuid = bajaMedicaConNota();
    $admin = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($admin)
        ->get('/api/v1/absences?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'sick_leave');

    Api::as($admin)
        ->get('/api/v1/absences/'.$uuid)
        ->assertOk()
        ->assertJsonPath('absence.note', 'Parte de baja por lumbalgia');
})->group('RF-GP-04', 'RQ-07');
