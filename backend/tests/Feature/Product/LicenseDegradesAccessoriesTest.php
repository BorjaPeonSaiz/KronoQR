<?php

declare(strict_types=1);

use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **EL RETROFIT DEL PASO 10**: las dos funcionalidades ya construidas que
 * ADR-023 lista como degradables, cableadas al punto unico de decision.
 *
 * - **Informes avanzados y comparacion entre periodos** (tarea 2.8): se
 *   desactivan, con el aviso de licencia y no con un error generico.
 * - **Presencia en tiempo real** (tarea 2.4): **degrada a sondeo, no se apaga**
 *   (ADR-011). Es la unica degradacion parcial de ADR-023, y apagarla del todo
 *   se percibiria como una averia en vez de como una licencia vencida.
 *
 * Y la mitad que importa: **el registro legal no se toca**. La exportacion para
 * la Inspeccion, la consulta de jornadas y el portal siguen respondiendo, y el
 * propio aviso los nombra para que quien se lo encuentre sepa por donde sacar
 * las horas de este mes.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    WorkforceFixtures::site();
    LicenseKeys::install();
});

/**
 * Activa una licencia con las funcionalidades indicadas y deja el reloj dentro
 * de su vigencia.
 *
 * @param  list<string>  $features
 */
function conFuncionalidades(array $features): void
{
    app()->instance(Clock::class, FixedClock::at('2026-06-15 09:00:00'));
    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand(
        LicenseKeys::current()->issue(['features' => $features]),
    ));
    app()->forgetInstance(FeatureGate::class);
}

/**
 * Activa una licencia caducada.
 */
function conLicenciaCaducada(): void
{
    app()->instance(Clock::class, FixedClock::at('2026-06-15 09:00:00'));
    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand(LicenseKeys::current()->issue([
        'valid_from' => '2025-01-01T00:00:00Z',
        'valid_until' => '2025-12-31T23:59:59Z',
    ])));
    app()->forgetInstance(FeatureGate::class);
}

function reportsToken(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
}

// --- 2.8: informes avanzados -------------------------------------------------

it('el informe por periodo funciona con la funcionalidad contratada', function (): void {
    conFuncionalidades(['advanced_reports']);

    Api::as(reportsToken())
        ->get('/api/v1/reports/period?from=2026-06-01&to=2026-06-07&granularity=range&group_by=employee')
        ->assertValidResponse(200);
})->group('RF-PD-05');

it('CON LA LICENCIA CADUCADA el informe por periodo responde con el aviso de licencia', function (): void {
    conLicenciaCaducada();

    $response = Api::as(reportsToken())
        ->withHeaders(['Accept-Language' => 'es'])
        ->get('/api/v1/reports/period?from=2026-06-01&to=2026-06-07&granularity=range&group_by=employee')
        ->assertValidResponse(402);

    expect($response->json('type'))->toBe('urn:kronoqr:problem:feature-not-licensed')
        ->and($response->json('feature'))->toBe('advanced_reports')
        ->and($response->json('restriction'))->toBe('license_expired')
        ->and($response->json('since'))->toStartWith('2025-12-31')
        // «Honesta» significa que dice QUE esta degradado, DESDE CUANDO y QUE
        // HACER, y ademas que sigue disponible (ADR-019).
        ->and($response->json('detail'))->toContain('31/12/2025')
        ->and($response->json('detail'))->toContain('Inspección de Trabajo')
        ->and($response->json('detail'))->toContain('Renueva la licencia');
})->group('RF-PD-05');

it('la exportacion del informe se degrada igual que su consulta', function (): void {
    // Un endpoint de descarga con la degradacion mas floja que su consulta es la
    // forma habitual de que la degradacion no sirva de nada.
    conLicenciaCaducada();

    Api::as(reportsToken())
        ->get('/api/v1/reports/period/export?from=2026-06-01&to=2026-06-07&granularity=range&group_by=employee&format=csv')
        ->assertStatus(402);
})->group('RF-PD-05');

it('el informe se degrada tambien cuando NO esta en el plan, con otro motivo', function (): void {
    // Renovar no arregla lo que nunca se compro. El motivo distinto es lo que
    // permite al panel decir «amplia el plan» en vez de «renueva».
    conFuncionalidades(['realtime_presence']);

    $response = Api::as(reportsToken())
        ->withHeaders(['Accept-Language' => 'es'])
        ->get('/api/v1/reports/period?from=2026-06-01&to=2026-06-07&granularity=range&group_by=employee')
        ->assertStatus(402);

    expect($response->json('restriction'))->toBe('not_in_plan')
        ->and($response->json('since'))->toBeNull()
        ->and($response->json('detail'))->toContain('no está incluida en el plan');
})->group('RF-PD-05');

it('degradar el informe NO degrada la exportacion legal ni la consulta del registro', function (): void {
    // La mitad que importa. Son dos endpoints del mismo modulo y uno se degrada
    // y el otro no, que es exactamente por lo que ADR-023 descarta «degradar por
    // modulos completos»: `Reporting` contiene a la vez la exportacion para la
    // Inspeccion y los informes de gestion.
    conLicenciaCaducada();

    Api::as(reportsToken())
        ->get('/api/v1/reports/period?from=2026-06-01&to=2026-06-07&granularity=range&group_by=employee')
        ->assertStatus(402);

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::AUDITOR)))
        ->get('/api/v1/reports/legal-export?from=2026-06-01&to=2026-06-07')
        ->assertOk();
})->group('RF-PD-05', 'RL-06');

// --- 2.4: presencia en tiempo real -------------------------------------------

it('CON LA LICENCIA CADUCADA la presencia DEGRADA A SONDEO y no se apaga', function (): void {
    // ADR-011 y ADR-023: la unica degradacion parcial. La vista sigue
    // funcionando y enseñando quien esta dentro, con menos frescura.
    conLicenciaCaducada();

    $response = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->get('/api/v1/attendance/live')
        ->assertValidResponse(200);

    expect($response->json('meta.realtime.enabled'))->toBeFalse()
        // El motivo va nulo porque en la suite tampoco hay Reverb configurado:
        // el caso con Reverb en pie —donde la licencia SI es la causa— es la
        // prueba 'CON REVERB CONFIGURADO' de mas abajo.
        ->and($response->json('meta.realtime.unavailable_reason'))->toBeNull()
        // Y el panel sabe cada cuanto sondear: la informacion sigue estando.
        ->and($response->json('meta.realtime.poll_interval_seconds'))->toBeGreaterThan(0)
        // El listado se sirve entero. Es una vista de LECTURA sobre el registro:
        // recortarla seria degradar el registro.
        ->and($response->json('data'))->toBeArray()
        ->and($response->json('meta.present_count'))->toBe(0);
})->group('RF-PD-05');

it('sin la funcionalidad en el plan, la presencia tambien sondea y lo dice', function (): void {
    conFuncionalidades(['advanced_reports']);
    conReverbConfigurado();

    $response = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->get('/api/v1/attendance/live')
        ->assertValidResponse(200);

    expect($response->json('meta.realtime.enabled'))->toBeFalse()
        ->and($response->json('meta.realtime.unavailable_reason'))->toBe('not_in_plan')
        ->and($response->json('meta.realtime.unavailable_since'))->toBeNull();
})->group('RF-PD-05');

it('cuando falta Reverb, el motivo NO es la licencia', function (): void {
    // El orden importa para el mensaje: si ademas falta configuracion de
    // Reverb, lo que hay que arreglar primero es eso, y anunciar «licencia
    // caducada» mandaria a quien lee a hablar con el comercial en lugar de con
    // quien despliega.
    conFuncionalidades(['realtime_presence']);

    $response = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->get('/api/v1/attendance/live')
        ->assertValidResponse(200);

    // En la suite el difusor es `null`, asi que no hay tiempo real por
    // configuracion y no por licencia.
    expect($response->json('meta.realtime.enabled'))->toBeFalse()
        ->and($response->json('meta.realtime.unavailable_reason'))->toBeNull();
})->group('RF-PD-05');

// --- 2.4: la degradacion es REAL, no cooperativa ------------------------------

/**
 * Deja Reverb configurado de verdad, como una instalacion de produccion.
 *
 * **Sin esto, las dos pruebas de arriba pasan sin mirar la licencia**: en la
 * suite `BROADCAST_CONNECTION=null`, asi que el tiempo real ya estaba apagado
 * por configuracion y `enabled: false` salia igual con la comprobacion de
 * licencia borrada. Medido: borrando el ultimo `return` de `blocker()`, la
 * suite seguia verde.
 */
function conReverbConfigurado(): void
{
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'kronoqr-test-key');
    config()->set('broadcasting.connections.reverb.secret', 'kronoqr-test-secret');
    config()->set('broadcasting.connections.reverb.app_id', 'kronoqr-test');

    // Los canales se registran sobre el difusor ACTIVO, y en el arranque de la
    // suite el activo es `null`: sin volver a cargarlos, el difusor de Pusher
    // nace sin ningun canal y todo responde 403 por el motivo equivocado.
    require base_path('routes/channels.php');
}

it('CON REVERB CONFIGURADO y licencia caducada, no hay tiempo real y se dice por que', function (): void {
    // La prueba que faltaba: aqui el tiempo real SI estaria disponible si no
    // fuera por la licencia, asi que lo que se observa es la licencia y no la
    // configuracion.
    conLicenciaCaducada();
    conReverbConfigurado();

    $response = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->get('/api/v1/attendance/live')
        ->assertValidResponse(200);

    expect($response->json('meta.realtime.enabled'))->toBeFalse()
        ->and($response->json('meta.realtime.unavailable_reason'))->toBe('license_expired')
        ->and($response->json('meta.realtime.unavailable_since'))->toStartWith('2025-12-31')
        // Y no se entrega lo que hace falta para conectarse igualmente.
        ->and($response->json('meta.realtime.key'))->toBeNull()
        ->and($response->json('meta.realtime.channels'))->toBe([])
        // La vista SIGUE: es una lectura sobre el registro y no se recorta.
        ->and($response->json('data'))->toBeArray()
        ->and($response->json('meta.realtime.poll_interval_seconds'))->toBeGreaterThan(0);
})->group('RF-PD-05');

it('CON REVERB CONFIGURADO y licencia caducada, la suscripcion al canal se rechaza', function (): void {
    // **La mitad que hace real la degradacion.** Sin esto, un cliente escrito a
    // mano que ignorase `meta.realtime.enabled` conservaria el tiempo real
    // intacto: la respuesta le decia que no y el canal le decia que si.
    conLicenciaCaducada();
    conReverbConfigurado();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->post('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-presence.all',
        ])
        ->assertStatus(403);
})->group('RF-PD-05');

it('CON REVERB CONFIGURADO y licencia vigente, la suscripcion se firma', function (): void {
    // El control positivo. Sin el, la prueba de arriba pasaria igual con la
    // autorizacion de canales rota del todo.
    conFuncionalidades(['realtime_presence']);
    conReverbConfigurado();

    $response = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->post('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-presence.all',
        ])
        ->assertOk();

    expect($response->json('auth'))->toBeString();
})->group('RF-PD-05');

it('CON REVERB APAGADO y licencia caducada, el motivo NO es la licencia', function (): void {
    // El orden de los motivos, comprobado con los DOS problemas a la vez. Antes
    // de la correccion, esta instalacion anunciaba `license_expired` y mandaba a
    // quien lo leyera a hablar con el comercial, cuando lo que hay que arreglar
    // es el despliegue.
    conLicenciaCaducada();

    // La suite ya trae `BROADCAST_CONNECTION=null`: no hay Reverb que valga.
    $response = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->get('/api/v1/attendance/live')
        ->assertValidResponse(200);

    expect($response->json('meta.realtime.enabled'))->toBeFalse()
        ->and($response->json('meta.realtime.unavailable_reason'))->toBeNull()
        ->and($response->json('meta.realtime.unavailable_since'))->toBeNull();
})->group('RF-PD-05');

it('el aviso sale con la fecha en el formato del idioma que se pide', function (): void {
    // La version anterior formateaba `d/m/Y` fijo dentro del dominio, asi que el
    // mensaje ingles decia «expired on 31/12/2025» — una fecha española en una
    // frase inglesa. El dominio no sabe en que idioma se va a leer: la fecha la
    // da el borde, como el texto.
    conLicenciaCaducada();

    $es = Api::as(reportsToken())
        ->withHeaders(['Accept-Language' => 'es'])
        ->get('/api/v1/reports/period?from=2026-06-01&to=2026-06-07&granularity=range&group_by=employee')
        ->assertStatus(402);

    $en = Api::as(reportsToken())
        ->withHeaders(['Accept-Language' => 'en'])
        ->get('/api/v1/reports/period?from=2026-06-01&to=2026-06-07&granularity=range&group_by=employee')
        ->assertStatus(402);

    expect($es->json('detail'))->toContain('31/12/2025')
        ->and($en->json('detail'))->toContain('31 Dec 2025')
        ->and($en->json('detail'))->not->toContain('31/12/2025')
        // Y el instante que viaja en el cuerpo es el mismo en los dos: una sola
        // conversion a UTC, y el dia no se separa del texto.
        ->and($es->json('since'))->toBe($en->json('since'))
        ->and($es->json('since'))->toStartWith('2025-12-31');
})->group('RF-PD-05');
