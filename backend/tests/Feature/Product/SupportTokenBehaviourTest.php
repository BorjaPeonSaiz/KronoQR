<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Product\Domain\ValueObject\SupportScope;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Product\SupportGrants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Que puede y que NO puede hacer un token de soporte (RF-PD-11, RL-19, ADR-020,
 * regla dura 16).
 *
 * ESTA ES LA SUITE QUE HACE CIERTA LA TABLA DE ALCANCES DEL CONTRATO. Sin ella,
 * esa tabla es prosa: los ambitos y los roles se pueden desincronizar sin que
 * nada falle, y el sintoma seria un token de soporte leyendo el registro horario
 * de la plantilla entera.
 *
 * Y la caducidad efectiva del requisito, que se comprueba de verdad —caducado
 * responde 401, revocado responde 401 en la peticion siguiente— y no por lo que
 * diga una columna.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
});

/** El registro horario de una persona: lo que `read_only` puede leer y los otros dos no. */
function unaJornadaAjena(): string
{
    $siteId = WorkforceFixtures::onlySiteId();

    return '/api/v1/employees/'.WorkforceFixtures::employee($siteId).'/workdays?from=2026-06-01&to=2026-06-07';
}

// --- Caducidad efectiva (RF-PD-11) -------------------------------------------

it('un token caducado responde 401 sin que nadie haya hecho nada', function (): void {
    // La promesa literal del requisito: «al expirar, el acceso deja de funcionar
    // sin que nadie haga nada». No hay tarea programada que lo cierre.
    $issued = SupportGrants::issue(hours: 1);
    SupportGrants::expire($issued->grant->uuid);

    Api::as($issued->token)->get('/api/v1/settings')->assertStatus(401);
})->group('RF-PD-11');

it('un token revocado responde 401 en la peticion SIGUIENTE', function (): void {
    // Revocar tiene que valer YA. Si dependiera de la caducidad del token, un
    // acceso retirado seguiria funcionando hasta 72 horas — que es un aviso, no
    // una revocacion.
    $issued = SupportGrants::issue(scope: SupportScope::Configuration);

    Api::as($issued->token)->get('/api/v1/settings')->assertOk();

    DB::table('support_grants')->where('uuid', $issued->grant->uuid)->update(['revoked_at' => now()]);

    Api::as($issued->token)->get('/api/v1/settings')->assertStatus(401);
})->group('RF-PD-11');

// --- `diagnostics`: el alcance de serie ---------------------------------------

it('diagnostics NO lee jornadas ajenas', function (): void {
    // No lleva `attendance:read`: se queda en el middleware. Es el alcance con el
    // que se resuelve la mayoria de las incidencias, y no alcanza ni un solo dato
    // personal (RL-19).
    Api::as(SupportGrants::tokenFor(SupportScope::Diagnostics))
        ->get(unaJornadaAjena())
        ->assertStatus(403);
})->group('RF-PD-11', 'RL-19');

it('diagnostics NO cambia la configuracion', function (): void {
    Api::as(SupportGrants::tokenFor(SupportScope::Diagnostics))
        ->patch('/api/v1/settings', ['settings' => ['ATTENDANCE_DEBOUNCE_SECONDS' => 90]])
        ->assertStatus(403);
})->group('RF-PD-11');

it('diagnostics NO lee la plantilla', function (): void {
    Api::as(SupportGrants::tokenFor(SupportScope::Diagnostics))
        ->get('/api/v1/employees')
        ->assertStatus(403);
})->group('RF-PD-11', 'RL-19');

// --- `read_only`: lectura completa, escritura ninguna --------------------------

/*
 * `read_only` LEE, Y ESE ES TODO SU SENTIDO.
 *
 * Actua como `admin` ante las policies —es lo unico que le permite llegar a las
 * pantallas del registro horario, porque el `auditor` de este producto esta
 * excluido de ellas a proposito— y lo que lo mantiene en solo lectura son sus
 * TRES AMBITOS: `attendance:read`, `employees:read` y `audit:read`, que no abren
 * ni una sola ruta de escritura en toda la API. Eso ultimo no se confia: lo
 * recorre `SupportScopeRoutesTest` sobre las rutas registradas.
 */
it('read_only SI lee el registro de jornada de una persona', function (): void {
    // Es para lo que existe: la incidencia que el paquete anonimizado no
    // resuelve, «a esta persona le salen ocho horas y deberian ser nueve».
    Api::as(SupportGrants::tokenFor(SupportScope::ReadOnly))
        ->get(unaJornadaAjena())
        ->assertOk();
})->group('RF-PD-11');

it('read_only SI lee la plantilla', function (): void {
    Api::as(SupportGrants::tokenFor(SupportScope::ReadOnly))
        ->get('/api/v1/employees')
        ->assertOk();
})->group('RF-PD-11');

it('read_only SI lee la presencia en tiempo real', function (): void {
    // La tercera pantalla que abre `attendance:read`, y la unica forma de ver
    // «quien esta dentro ahora mismo» cuando la incidencia es que el panel no
    // cuadra con la realidad.
    Api::as(SupportGrants::tokenFor(SupportScope::ReadOnly))
        ->get('/api/v1/attendance/live')
        ->assertOk();
})->group('RF-PD-11');

it('read_only NO cambia la configuracion', function (): void {
    // Solo lectura: no lleva `settings:*` y se queda en el middleware.
    Api::as(SupportGrants::tokenFor(SupportScope::ReadOnly))
        ->patch('/api/v1/settings', ['settings' => ['ATTENDANCE_DEBOUNCE_SECONDS' => 90]])
        ->assertStatus(403);
})->group('RF-PD-11');

it('read_only NO corrige fichajes', function (): void {
    // Corregir una hora trabajada es un acto del cliente con valor legal
    // (RN-13, RL-04). El fabricante no lo hace ni con permiso.
    Api::as(SupportGrants::tokenFor(SupportScope::ReadOnly))
        ->post('/api/v1/shift-entries', [])
        ->assertStatus(403);
})->group('RF-PD-11', 'RL-04');

// --- `configuration`: ajustes si, lo demas no ---------------------------------

it('configuration SI cambia la configuracion', function (): void {
    Api::as(SupportGrants::tokenFor(SupportScope::Configuration))
        ->patch('/api/v1/settings', ['settings' => ['ATTENDANCE_DEBOUNCE_SECONDS' => 90]])
        ->assertOk();
})->group('RF-PD-11');

it('configuration NO activa licencias', function (): void {
    // Lo que se contrato lo decide quien firma el contrato, y el fabricante no
    // se activa a si mismo un plan en la instalacion del cliente.
    Api::as(SupportGrants::tokenFor(SupportScope::Configuration))
        ->post('/api/v1/license/activate', ['signed_key' => 'KQL1.a.b'])
        ->assertStatus(403);

    expect(DB::table('license')->count())->toBe(0);
})->group('RF-PD-11');

it('configuration NO lee jornadas ajenas', function (): void {
    // Cambiar un ajuste y leer el registro de la plantilla son dos potestades
    // distintas, y quien necesita la primera no necesita la segunda.
    Api::as(SupportGrants::tokenFor(SupportScope::Configuration))
        ->get(unaJornadaAjena())
        ->assertStatus(403);
})->group('RF-PD-11', 'RL-19');

it('configuration NO genera la exportacion para la Inspeccion', function (): void {
    Api::as(SupportGrants::tokenFor(SupportScope::Configuration))
        ->get('/api/v1/reports/legal-export?from=2026-06-01&to=2026-06-07')
        ->assertStatus(403);
})->group('RF-PD-11', 'RL-19');

it('ningun alcance emite ni revoca credenciales', function (SupportScope $scope): void {
    Api::as(SupportGrants::tokenFor($scope))
        ->post('/api/v1/credentials', [])
        ->assertStatus(403);
})->with(SupportScope::cases())->group('RF-PD-11', 'RL-19');

it('ningun alcance da de alta ni de baja a nadie', function (SupportScope $scope): void {
    Api::as(SupportGrants::tokenFor($scope))
        ->post('/api/v1/employees', [])
        ->assertStatus(403);
})->with(SupportScope::cases())->group('RF-PD-11', 'RL-19');

/*
 * LA PUERTA QUE FALTA POR CERRAR, Y ES LA MAS IMPORTANTE DE TODAS.
 *
 * `POST /api/v1/diagnostics/bundle` con `include_personal_data: true` es una
 * accion del CLIENTE (RL-19): un actor de soporte no puede sacar de la
 * instalacion los datos de la plantilla, ni siquiera con el alcance mas amplio.
 *
 * La prueba queda escrita y marcada: el endpoint lo implementa la otra mitad de
 * la tarea 5.9, y anotarla ahora es lo que impide que se cierre sin ella.
 */
it('ningun alcance incluye datos personales en un paquete de diagnostico', function (SupportScope $scope): void {
    Api::as(SupportGrants::tokenFor($scope))
        ->post('/api/v1/diagnostics/bundle', ['include_personal_data' => true, 'period_days' => 7])
        ->assertStatus(403);
})->with(SupportScope::cases())
    ->skip(fn (): bool => ! app('router')->has('product.diagnostics.bundle'),
        'El endpoint de diagnostico es de la otra mitad de la tarea 5.9 y todavia no esta registrado.')
    ->group('RF-PD-11', 'RL-19');

// --- El asistente de puesta en marcha es del cliente, no de soporte --------

it('configuration NO completa ni avanza el asistente de puesta en marcha', function (): void {
    // Sus rutas viajan bajo `settings:*`, asi que el ambito de `configuration`
    // las alcanza; es `SetupPolicy` quien cierra la puerta a todo actor de
    // soporte. `setup.complete` es irreversible y deja asiento: poner en marcha
    // la instalacion lo decide quien la contrata, aunque quien la configure sea
    // el fabricante.
    $token = SupportGrants::tokenFor(SupportScope::Configuration);

    Api::as($token)->get('/api/v1/setup/steps')->assertStatus(403);
    Api::as($token)->call('PUT', '/api/v1/setup/steps/organisation', [])->assertStatus(403);
    Api::as($token)->post('/api/v1/setup/complete', [])->assertStatus(403);
})->group('RF-PD-11', 'RF-PD-03');

// --- La policy cierra aunque el ambito abra (regla dura 18) -------------------

/*
 * LAS DOS COMPROBACIONES DEL §7.3, Y LA SEGUNDA PROBADA DE VERDAD.
 *
 * Con los ambitos reales, un token de soporte no llega ni al controlador de
 * licencia: lo para el middleware. Eso deja la policy sin ejercitar, y una que
 * devolviera `true` pasaria desapercibida hasta el dia en que alguien emitiera
 * un token a mano o ampliara la lista de alcances.
 *
 * Asi que se emite a mano el token que el producto nunca emite —una concesion de
 * soporte con `license:*`— y se comprueba que **la policy lo rechaza igual**. Es
 * literalmente el escenario contra el que existe la segunda puerta.
 */
it('la policy de licencia rechaza a un actor de soporte aunque su token lleve license:*', function (string $method, string $uri, array $body): void {
    $token = SupportGrants::tokenWithAbilities([TokenAbility::LICENSE_ALL->value]);

    Api::as($token)->call($method, $uri, $body)->assertStatus(403);
})->with([
    // La lectura tambien, y no es celo: la respuesta lleva la razon social del
    // cliente, su plan y sus cifras de plantilla. Es informacion comercial del
    // cliente sobre su propio contrato.
    'consulta' => ['GET', '/api/v1/license', []],
    'activacion' => ['POST', '/api/v1/license/activate', ['signed_key' => 'KQL1.a.b']],
])->group('RF-PD-11', 'RF-PD-04');

it('la policy de soporte rechaza a un actor de soporte aunque su token lleve support:*', function (string $method, string $uri, array $body): void {
    // La escalada completa: concederse a si mismo mas acceso, o revocar la
    // concesion que el cliente acaba de cortar. Quien recibe el acceso no decide
    // cuanto acceso tiene.
    $token = SupportGrants::tokenWithAbilities([TokenAbility::SUPPORT_ALL->value]);

    Api::as($token)->call($method, $uri, $body)->assertStatus(403);
})->with([
    'lista' => ['GET', '/api/v1/support/grants', []],
    'concesion' => ['POST', '/api/v1/support/grants', ['reason' => 'Me concedo mas acceso']],
    'revocacion' => ['DELETE', '/api/v1/support/grants/0199f6a2-4c1e-7d3b-8a90-1b2c3d4e5f60', []],
])->group('RF-PD-11');

// --- El perfil de cumplimiento lo fija el cliente (RL-01, RL-02, regla 14) ----

it('configuration LEE el perfil de cumplimiento', function (): void {
    // Leer los umbrales es justo lo que hace falta para diagnosticar por que
    // salta una incidencia, y no cambia nada.
    Api::as(SupportGrants::tokenFor(SupportScope::Configuration))
        ->get('/api/v1/compliance-profile')
        ->assertOk();
})->group('RF-PD-11');

it('configuration NO cambia el perfil de cumplimiento', function (): void {
    /*
     * La excepcion dentro de `configuration`, y la decide `seguridad-cumplimiento`.
     *
     * Ese alcance lleva `settings:*`, y ese ambito cubre tambien el perfil (§7.3,
     * precision 4), asi que **la ruta es alcanzable y la cierra la policy** — que
     * es exactamente el diseño de las dos comprobaciones, no un descuido.
     *
     * Lo que hay detras son los umbrales LEGALES y `retention_years`: los fija la
     * jurisdiccion del cliente y su asesoria (RL-01, RL-02, regla dura 14). Bajar
     * la retencion acorta la vida de un registro con valor probatorio, y mover un
     * umbral cambia las incidencias que se detectan sobre horas ya trabajadas.
     * Ninguna de las dos la hace quien esta arreglando una incidencia tecnica.
     */
    Api::as(SupportGrants::tokenFor(SupportScope::Configuration))
        ->patch('/api/v1/compliance-profile', ['retention_years' => 6])
        ->assertStatus(403);
})->group('RF-PD-11', 'RL-01', 'RL-02');

// --- Cerrar sesion no es revocar (ADR-020) -----------------------------------

it('el logout de un actor de soporte no toca la concesion ni su token', function (): void {
    /*
     * `POST /auth/logout` no lleva ambito —lo alcanzan las tres clases de
     * sesion— asi que un token de soporte llega. Antes de la 5.9 lo habria
     * tratado como una sesion de PORTAL: le habria borrado el token sin marcar
     * `revoked_at` —dejando una concesion que el panel enseña «activa» y que ya
     * no funciona— y habria escrito `auth.logout` en el canal del empleado.
     *
     * Ahora responde `204` y no hace nada. Un token de soporte no es una sesion:
     * es una credencial con caducidad fija que **el cliente** controla, y
     * retirarla es una potestad que la policy le niega al fabricante.
     */
    $issued = SupportGrants::issue(scope: SupportScope::Configuration);

    Api::as($issued->token)->post('/api/v1/auth/logout')->assertStatus(204);

    // La concesion sigue viva, el token sigue valiendo y nadie ha auditado un
    // cierre de sesion que no ha ocurrido.
    expect(DB::table('support_grants')->where('uuid', $issued->grant->uuid)->value('revoked_at'))->toBeNull()
        ->and(DB::table('audit_log')->where('action', 'auth.logout')->count())->toBe(0);

    Api::as($issued->token)->get('/api/v1/settings')->assertOk();
})->group('RF-PD-11', 'RS-12');
