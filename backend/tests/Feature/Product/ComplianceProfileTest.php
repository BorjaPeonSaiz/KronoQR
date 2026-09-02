<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\CompliancePolicyProvider;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET` y `PATCH /api/v1/compliance-profile` — los umbrales **legales** del
 * centro (RF-PD-07, regla dura 14, ADR-017).
 *
 * Es la mitad de «nada especifico de un cliente vive en el codigo» que se ocupa
 * de la ley: un cliente con otro convenio ajusta una fila y no despliega nada.
 * Lo que estas pruebas fijan es que sea editable **de verdad**, que el cambio
 * llegue al dominio y que quede constancia de quien lo hizo.
 *
 * Las respuestas se validan contra `openapi.yaml` con Spectator: el contrato es
 * la fuente de verdad (ADR-013).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');

    // El centro de la instalacion (ADR-040). Sin el no hay perfil vigente que
    // resolver y las dos rutas responden `404`, que es el comportamiento de
    // antes de la puesta en marcha y tiene su propia prueba.
    WorkforceFixtures::site();
});

function profileAdminToken(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
}

it('devuelve el perfil ES-hosteleria tal y como se entrega de serie', function (): void {
    // Los ocho numeros del perfil espanol, escritos aqui uno a uno. Es el
    // producto que se instala en casa del cliente: si alguno cambiara sin querer,
    // cambiaria lo que se considera un incumplimiento en veinte hoteles.
    $response = Api::as(profileAdminToken())->get('/api/v1/compliance-profile')
        ->assertValidRequest()
        ->assertValidResponse(200);

    expect($response->json('data'))->toMatchArray([
        'name' => 'ES-hosteleria',
        'jurisdiction' => 'ES',
        'min_rest_hours' => 12,
        'max_daily_hours' => 9,
        'max_weekly_hours' => 40,
        'break_required_after_hours' => 6,
        'week_starts_on' => 1,
        'holiday_calendar' => [],
        'retention_years' => 4,
        'is_default' => true,
    ]);
})->group('RF-PD-07');

it('dice como se ha resuelto el perfil', function (bool $assigned, string $expected): void {
    // Un centro sin perfil asignado hereda los cambios del perfil por defecto y
    // uno con perfil propio no: quien edita necesita saber cual de las dos cosas
    // esta tocando.
    if ($assigned) {
        DB::table('sites')->update([
            'compliance_profile_id' => DB::table('compliance_profiles')->where('is_default', true)->value('id'),
        ]);
    }

    Api::as(profileAdminToken())->get('/api/v1/compliance-profile')
        ->assertValidResponse(200)
        ->assertJsonPath('data.source', $expected);
})->with([
    'centro con perfil asignado' => [true, 'site'],
    'centro sin perfil, cae en el de la instalacion' => [false, 'installation_default'],
])->group('RF-PD-07');

it('responde 404 antes de la puesta en marcha, cuando no hay centro', function (): void {
    // RF-PD-03. Sin centro no hay perfil vigente que resolver. Inventar uno
    // seria escribir un umbral legal en el codigo (regla dura 14).
    DB::table('sites')->delete();

    Api::as(profileAdminToken())->get('/api/v1/compliance-profile')->assertValidResponse(404);
})->group('RF-PD-07');

it('cambia un umbral y devuelve el perfil completo', function (): void {
    $response = Api::as(profileAdminToken())
        ->patch('/api/v1/compliance-profile', ['min_rest_hours' => 10])
        ->assertValidRequest()
        ->assertValidResponse(200);

    expect($response->json('data.min_rest_hours'))->toBe(10)
        // El resto no se ha tocado: un `PATCH` parcial es parcial.
        ->and($response->json('data.max_daily_hours'))->toBe(9)
        ->and($response->json('data.name'))->toBe('ES-hosteleria');
})->group('RF-PD-07');

it('el umbral cambiado llega al dominio por el puerto, que es el criterio de la tarea', function (): void {
    // El resultado esperado literal de la 5.2: cambiar el perfil cambia lo que
    // recibe la regla, **sin tocar una linea de codigo**. Si esta prueba
    // fallara, RN-10 estaria leyendo su umbral de otro sitio.
    Api::as(profileAdminToken())
        ->patch('/api/v1/compliance-profile', ['min_rest_hours' => 10, 'max_daily_hours' => 8])
        ->assertValidResponse(200);

    /** @var int $siteId */
    $siteId = DB::table('sites')->value('id');
    $policy = app(CompliancePolicyProvider::class)->forSite($siteId);

    expect($policy->minimumRestMinutes)->toBe(600)
        ->and($policy->maximumDailyMinutes)->toBe(480)
        ->and($policy->breakRequiredAfterMinutes)->toBe(360);
})->group('RF-PD-07', 'RN-10', 'RN-11');

it('guarda los tres campos que todavia no tiene consumidor', function (): void {
    // `max_weekly_hours`, `week_starts_on` y `holiday_calendar` los estrena la
    // tarea 3.4. Que no los lea ninguna regla no los hace decorativos: el cliente
    // tiene que poder dejar cargado su convenio y sus festivos hoy.
    $response = Api::as(profileAdminToken())
        ->patch('/api/v1/compliance-profile', [
            'max_weekly_hours' => 38,
            'week_starts_on' => 7,
            'holiday_calendar' => ['2026-12-25', '2026-01-01'],
        ])
        ->assertValidRequest()
        ->assertValidResponse(200);

    expect($response->json('data.max_weekly_hours'))->toBe(38)
        ->and($response->json('data.week_starts_on'))->toBe(7)
        // Ordenado: reordenar la lista no puede leerse como un cambio.
        ->and($response->json('data.holiday_calendar'))->toBe(['2026-01-01', '2026-12-25']);
})->group('RF-PD-07');

it('acepta vaciar el calendario de festivos', function (): void {
    // El calendario caduca cada 31 de diciembre y hay que poder rehacerlo. Sin
    // esto, la unica forma de quitar un festivo mal cargado seria `psql`.
    $token = profileAdminToken();

    Api::as($token)->patch('/api/v1/compliance-profile', ['holiday_calendar' => ['2026-12-25']])
        ->assertValidResponse(200);

    Api::as($token)->patch('/api/v1/compliance-profile', ['holiday_calendar' => []])
        ->assertValidResponse(200)
        ->assertJsonPath('data.holiday_calendar', []);
})->group('RF-PD-07');

it('rechaza un valor fuera de rango señalando el campo', function (string $field, mixed $value): void {
    // El error peligroso es el silencioso: `max_daily_hours = 90` —el 9 con un
    // cero de mas— no rompe nada y apaga RN-11 hasta que alguien compara una
    // nomina con el convenio.
    Api::as(profileAdminToken())
        ->patch('/api/v1/compliance-profile', [$field => $value])
        ->assertValidResponse(422)
        ->assertJsonStructure(['errors' => [$field]]);
})->with([
    'jornada diaria de 90 horas' => ['max_daily_hours', 90],
    'descanso de 0 horas' => ['min_rest_hours', 0],
    'pausa negativa' => ['break_required_after_hours', -1],
    'semana que empieza el dia 8' => ['week_starts_on', 8],
    'retencion de 100 anos' => ['retention_years', 100],
    'umbral entrecomillado' => ['min_rest_hours', '11'],
])->group('RF-PD-07');

it('rechaza una jornada semanal por debajo de la diaria', function (): void {
    // La invariante que ningun campo puede comprobar solo: la peticion cambia la
    // diaria y la semanal viene de la fila que ya hay.
    Api::as(profileAdminToken())
        ->patch('/api/v1/compliance-profile', ['max_weekly_hours' => 8])
        ->assertValidResponse(422)
        ->assertJsonStructure(['errors' => ['max_weekly_hours']]);
})->group('RF-PD-07');

it('rechaza un festivo que no es una fecha', function (mixed $calendar): void {
    Api::as(profileAdminToken())
        ->patch('/api/v1/compliance-profile', ['holiday_calendar' => $calendar])
        ->assertValidResponse(422);
})->with([
    'texto libre' => [['Navidad']],
    'formato espanol' => [['25/12/2026']],
    'dia que no existe' => [['2026-02-30']],
    'repetido' => [['2026-12-25', '2026-12-25']],
])->group('RF-PD-07');

it('rechaza tocar lo que no es editable', function (string $field, mixed $value): void {
    // Ignorarlos en silencio dejaria a quien los envia creyendo que ha cambiado
    // algo. `is_default` ademas es lo que hace que el centro resuelva su perfil.
    Api::as(profileAdminToken())
        ->patch('/api/v1/compliance-profile', [$field => $value])
        ->assertValidResponse(422)
        ->assertJsonStructure(['errors' => [$field]]);
})->with([
    'jurisdiccion' => ['jurisdiction', 'PT'],
    'perfil por defecto' => ['is_default', false],
    'identificador' => ['id', 7],
])->group('RF-PD-07');

it('rechaza un PATCH sin ningun campo', function (): void {
    // Un `PATCH` vacio no es idempotente: es una peticion sin intencion.
    Api::as(profileAdminToken())
        ->patch('/api/v1/compliance-profile', [])
        ->assertValidResponse(422);
})->group('RF-PD-07');

it('dice si el perfil sigue siendo el de serie o alguien lo ha ajustado', function (): void {
    // `null` significa «tal como se instalo». Es lo primero que necesita ver
    // quien abre la pantalla, y lo que `audit_log` no contesta barato.
    $token = profileAdminToken();

    Api::as($token)->get('/api/v1/compliance-profile')
        ->assertValidResponse(200)
        ->assertJsonPath('data.updated_at', null);

    $response = Api::as($token)
        ->patch('/api/v1/compliance-profile', ['min_rest_hours' => 11])
        ->assertValidResponse(200);

    expect($response->json('data.updated_at'))->toBeString();
})->group('RF-PD-07');

it('rechaza un festivo repetido aunque el nucleo lo deduplique al leer', function (): void {
    // Las dos politicas conviven a proposito y comparten el mismo parseo: al
    // **leer** se deduplica con tolerancia —una fila corrupta no puede dejar a un
    // centro sin calcular— y al **escribir** se rechaza, porque aqui hay alguien
    // delante a quien decirselo.
    $response = Api::as(profileAdminToken())
        ->patch('/api/v1/compliance-profile', ['holiday_calendar' => ['2026-12-25', '2026-12-25']])
        ->assertValidResponse(422);

    /** @var array<string, mixed> $errors */
    $errors = $response->json('errors');

    // La regla `distinct` cuelga el error de la posicion concreta
    // (`holiday_calendar.0`), que es mas util que del campo entero: dice cual de
    // las fechas sobra.
    expect(array_filter(array_keys($errors), static fn (string $field): bool => str_starts_with($field, 'holiday_calendar')))
        ->not->toBe([]);
})->group('RF-PD-07');
