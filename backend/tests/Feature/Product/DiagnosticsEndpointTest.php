<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `POST /api/v1/diagnostics/bundle` — el paquete que el cliente genera con un
 * clic y envia a soporte (RF-PD-09, ADR-020, doc 05 §10.6).
 *
 * Las respuestas se validan contra `openapi.yaml` con Spectator: el contrato es
 * la fuente de verdad (ADR-013), y aqui importa mas que en ningun otro endpoint
 * porque la forma del paquete **es** lo que un runbook del fabricante da por
 * hecho al abrirlo.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    WorkforceFixtures::site();
    LicenseKeys::install();
});

function tokenDeDiagnostico(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
}

/**
 * Un tramo de hoy para una persona, escrito con el constructor de consultas.
 *
 * La seccion `personal_data` solo lleva las fichas de quien aparece en el
 * periodo (RL-19, minimizacion), asi que una prueba que quiera ver una ficha
 * tiene que dar de alta la actividad que la justifica.
 */
function fichajeReciente(int $siteId, string $employeeUuid): void
{
    $employeeId = DB::table('employees')->where('uuid', $employeeUuid)->value('id');

    DB::table('shift_entries')->insert([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $employeeId,
        'site_id' => $siteId,
        'work_date' => now()->toDateString(),
        'clocked_in_at' => now()->subHours(4)->toIso8601String(),
        'clocked_out_at' => now()->subHour()->toIso8601String(),
        'duration_minutes' => 180,
        'status' => 'closed',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => 'qr_kiosk',
        'version' => 1,
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);
}
it('devuelve el paquete anonimizado sin cuerpo, que es el caso normal', function (): void {
    // El boton «Generar y descargar» del panel manda un POST vacio. El valor por
    // defecto ES el producto (RL-19): anonimizado, sin que el cliente tenga que
    // pedirlo.
    // SIN `assertValidRequest()`, y a proposito: el contrato declara el cuerpo
    // `required: false` y aqui no se manda ninguno, que es exactamente lo que
    // hace el panel. El validador de peticiones de Spectator exige el objeto en
    // cuanto la cabecera dice JSON, asi que la peticion **con** cuerpo se
    // valida en el caso de los datos personales, mas abajo.
    $response = Api::as(tokenDeDiagnostico())
        ->post('/api/v1/diagnostics/bundle')
        ->assertValidResponse(200);

    expect($response->json('manifest.anonymized'))->toBeTrue()
        ->and($response->json('manifest.schema_version'))->toBe(1)
        ->and($response->json('manifest.generated_by'))->toBe('user')
        ->and($response->json('manifest.sha256'))->toMatch('/^[0-9a-f]{64}$/')
        // Las diez secciones obligatorias del contrato, y `personal_data` no.
        ->and($response->json('manifest.sections'))->toBe([
            'installation', 'configuration', 'services', 'doctor', 'license',
            'kiosks', 'error_events', 'metrics', 'updates', 'audit',
        ])
        ->and($response->json())->not->toHaveKey('personal_data');
})->group('RF-PD-09');

it('lleva el nombre del fichero en la cabecera, para que el navegador lo guarde', function (): void {
    // Sin esto el panel tendria que inventarse un nombre, y `diagnostics.json`
    // repetido cuatro veces en una bandeja de correo no se puede ordenar.
    $response = Api::as(tokenDeDiagnostico())
        ->post('/api/v1/diagnostics/bundle')
        ->assertValidResponse(200);

    $disposition = $response->headers->get('Content-Disposition');

    expect($disposition)->toContain('attachment; filename="kronoqr-diagnostics-')
        ->and($disposition)->toEndWith('.json"')
        // No se cachea en ningun sitio: es una foto de un instante y puede
        // llevar datos personales.
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
})->group('RF-PD-09');

it('la huella del manifiesto verifica sobre el documento entregado', function (): void {
    // Es lo que permite a soporte detectar un paquete cortado por el correo
    // antes de pasarse una hora diagnosticando un fichero a medias.
    $document = Api::as(tokenDeDiagnostico())
        ->post('/api/v1/diagnostics/bundle')
        ->assertValidResponse(200)
        ->json();

    expect(DiagnosticsBundle::digestMatches($document))->toBeTrue();
})->group('RF-PD-09');

it('nunca lleva la razon social del cliente en el estado de licencia', function (): void {
    // El riesgo que el doc 07 §6 dejo anotado en la tarea 5.3 y que se cierra
    // aqui: `customer_name` viaja en el asiento `license.activated`, que es del
    // cliente, y no puede salir de la instalacion.
    LicenseKeys::grantAll();

    $response = Api::as(tokenDeDiagnostico())
        ->post('/api/v1/diagnostics/bundle')
        ->assertValidResponse(200);

    expect($response->json('license.state'))->toBe('valid')
        ->and($response->json('license'))->not->toHaveKey('customer_name')
        // La huella corta si: es lo que sirve para confirmar por telefono que la
        // clave activada es la que se envio.
        ->and($response->json('license.key_fingerprint'))->toBeString()
        ->and(json_encode($response->json(), JSON_THROW_ON_ERROR))
        ->not->toContain(LicenseKeys::defaults()['customer_name']);
})->group('RF-PD-09');

it('incluye datos personales solo cuando se piden, y lo dice en el manifiesto', function (): void {
    $siteId = WorkforceFixtures::onlySiteId();

    // Quien ficho dentro de la ventana: su ficha hace falta para poder leer el
    // resto de la seccion, porque los tramos la referencian por `employee_uuid`.
    $conActividad = WorkforceFixtures::employee($siteId, firstName: 'Marta', lastName: 'Lopez Garcia');
    // Y quien no ha hecho nada: no pinta nada en el paquete (RL-19,
    // minimizacion). Sacarla seria enviar la plantilla entera del hotel para
    // diagnosticar el problema de una persona.
    WorkforceFixtures::employee($siteId, firstName: 'Anastasio', lastName: 'Sin Actividad');

    fichajeReciente($siteId, $conActividad);

    $response = Api::as(tokenDeDiagnostico())
        ->post('/api/v1/diagnostics/bundle', ['include_personal_data' => true, 'period_days' => 7])
        ->assertValidRequest()
        ->assertValidResponse(200);

    $personal = json_encode($response->json('personal_data'), JSON_THROW_ON_ERROR);

    expect($response->json('manifest.anonymized'))->toBeFalse()
        ->and($response->json('manifest.sections'))->toContain('personal_data')
        ->and($response->json('personal_data.period_days'))->toBe(7)
        ->and($personal)->toContain('Marta Lopez Garcia')
        ->and($personal)->not->toContain('Anastasio')
        ->and($response->json('personal_data.employees.total'))->toBe(1);
})->group('RF-PD-09', 'RL-19');

it('rechaza un periodo por encima del maximo configurado', function (): void {
    // El limite existe para que la bandera no se convierta en una exportacion
    // del registro horario por la puerta de atras (RL-19).
    Api::as(tokenDeDiagnostico())
        ->post('/api/v1/diagnostics/bundle', ['include_personal_data' => true, 'period_days' => 400])
        ->assertValidResponse(422);
})->group('RF-PD-09', 'RL-19');

it('rechaza un campo que el contrato no declara', function (): void {
    Api::as(tokenDeDiagnostico())
        ->post('/api/v1/diagnostics/bundle', ['include_personal_data' => true, 'sections' => ['todo']])
        ->assertStatus(422);
})->group('RF-PD-09');

it('corta a las tres peticiones por minuto', function (): void {
    // Zona propia y mas estrecha que la de gestion: generar el paquete recorre
    // la instalacion entera y pasa por la misma base de datos que cada fichaje.
    $token = tokenDeDiagnostico();

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        Api::as($token)->post('/api/v1/diagnostics/bundle')->assertStatus(200);
    }

    Api::as($token)->post('/api/v1/diagnostics/bundle')->assertStatus(429);
})->group('RF-PD-09');

it('deja el asiento de generacion en audit_log', function (): void {
    Api::as(tokenDeDiagnostico())->post('/api/v1/diagnostics/bundle')->assertStatus(200);

    $entry = DB::table('audit_log')->where('action', 'diagnostics.bundle_generated')->first();

    expect($entry)->not->toBeNull();
})->group('RF-PD-09', 'RL-04');
