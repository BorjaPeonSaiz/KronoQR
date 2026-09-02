<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\OperationalSettingsProvider;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spectator\Spectator;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Una fila de configuracion corrupta no impide fichar** (RF-PD-01, regla dura
 * 19).
 *
 * ## El fallo que esto impide
 *
 * `RegisterScanHandler` pide la configuracion operativa en CADA escaneo, y
 * resolverla recorre las nueve claves del catalogo. Si esa resolucion pudiera
 * lanzar, bastaria una fila editada a mano durante una intervencion de soporte
 * —`BRANDING_ACCENT_COLOR = 'rgb(17,24,39)'`, que ni siquiera es una clave que el
 * fichaje consuma— para que `POST /api/v1/scan` respondiera un error y **nadie
 * pudiera pasar la tarjeta**. El sintoma no se parece en nada a la causa y se
 * descubre en la puerta de servicio a las seis de la mañana.
 *
 * La regla es **lectura tolerante, escritura estricta**: al leer se descarta la
 * fila y rige el valor de serie; al escribir se rechaza con un `422`.
 *
 * ## Y descartar no puede ser silencioso
 *
 * Una clave de impacto `worked_hours` descartada cambia los minutos que se
 * calculan. Por eso el descarte viaja en `meta.invalid_keys` de
 * `GET /api/v1/settings` y deja un `warning` en el log.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * Escribe una fila con un valor que su clave no admite, saltandose la API.
 *
 * A mano y con `DB::table()` a proposito: por la API esto es imposible —el
 * `PATCH` lo rechaza— y esa es justo la unica forma en que puede llegar a la
 * tabla de un cliente.
 */
function corruptSetting(string $key, string $json): void
{
    DB::table('installation_settings')->updateOrInsert(
        ['key' => $key],
        ['value' => $json, 'updated_at' => '2026-01-01 00:00:00+00'],
    );
}

it('deja fichar con una fila de marca corrupta en la base de datos', function (): void {
    // El caso literal del hallazgo: una clave que el camino de fichaje **ni
    // siquiera consume**, con un valor que su definicion rechaza.
    corruptSetting('BRANDING_ACCENT_COLOR', '"rgb(17,24,39)"');

    $scenario = AttendanceFixtures::scenario();

    $card = 'FH1.a3.tarjeta000000000000001.firma';
    app()->instance(CredentialResolver::class, FakeCredentialResolver::new()->resolving($card, $scenario['employee']));
    app()->instance(Clock::class, FixedClock::at('2026-03-14 06:00:00'));
    app()->instance(ScanMetrics::class, new RecordingScanMetrics);

    $scanId = Str::uuid7()->toString();

    Api::as($scenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T06:00:00Z',
            'qr_payload' => $card,
        ])
        ->assertStatus(200)
        ->assertJsonPath('action', 'clock_in');

    expect(DB::table('shift_entries')->count())->toBe(1);
})->group('RF-PD-01', 'RF-AT-01', 'RNF-D-03');

it('aplica el valor de serie de la clave corrupta y deja intactas las demas', function (): void {
    // La corrupcion se acota a su clave: las otras ocho se resuelven como
    // siempre. Y la clave corrupta no queda «sin valor», queda con el del
    // producto.
    corruptSetting('ATTENDANCE_DEBOUNCE_SECONDS', '"sesenta"');
    corruptSetting('ATTENDANCE_MAX_SHIFT_HOURS', '10');

    $settings = app(OperationalSettingsProvider::class)->forSite(1);

    expect($settings->debounceSeconds)->toBe(60)
        ->and($settings->anomalousShiftMinutes)->toBe(10 * 60);
})->group('RF-PD-01', 'RF-AT-06');

it('sirve la marca del producto cuando la fila de marca esta corrupta', function (): void {
    corruptSetting('BRANDING_ACCENT_COLOR', '"rgb(17,24,39)"');

    expect(app(BrandingProvider::class)->current()->accentColor)->toBe('#111827');
})->group('RF-PD-01');

it('publica la fila descartada en meta.invalid_keys, con su motivo y su impacto', function (): void {
    // La otra mitad: el descarte no puede ser silencioso. Quien administra tiene
    // que poder ver que lo que se aplica no es lo que hay escrito.
    corruptSetting('ATTENDANCE_DEBOUNCE_SECONDS', '"sesenta"');

    $response = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->get('/api/v1/settings')
        ->assertValidRequest()
        ->assertValidResponse(200);

    expect($response->json('meta.invalid_keys'))->toHaveCount(1)
        ->and($response->json('meta.invalid_keys.0.key'))->toBe('ATTENDANCE_DEBOUNCE_SECONDS')
        // La ventana anti-rebote es la unica clave del catalogo que mueve
        // minutos: descartarla cambia el total de una jornada.
        ->and($response->json('meta.invalid_keys.0.affects_worked_hours'))->toBeTrue()
        ->and($response->json('meta.invalid_keys.0.reason'))->toBeString()
        ->and($response->json('meta.unknown_keys'))->toBe([]);
})->group('RF-PD-01');

it('anuncia el descarte en el log, sin el valor guardado', function (): void {
    // El log viaja a Loki y al paquete de diagnostico, que sale de la
    // instalacion (ADR-020): lleva el nombre de la clave y el motivo tecnico,
    // nunca el valor, que es dato del cliente.
    corruptSetting('BRANDING_APP_NAME', '"'.str_repeat('N', 120).'"');

    $logged = [];

    Log::listen(function (MessageLogged $event) use (&$logged): void {
        if ($event->message === 'product.settings_anomaly') {
            $logged[] = $event;
        }
    });

    app(OperationalSettingsProvider::class)->forSite(1);

    expect($logged)->toHaveCount(1)
        ->and($logged[0]->level)->toBe('warning');

    $context = json_encode($logged[0]->context, JSON_THROW_ON_ERROR);

    expect($context)->toContain('BRANDING_APP_NAME')
        ->and($context)->not->toContain(str_repeat('N', 120));
})->group('RF-PD-01', 'RS-11');

it('resuelve una incoherencia de idiomas sin romper, y la anota', function (): void {
    // Invariante ENTRE claves. Al leer se aplica el primer idioma disponible; al
    // escribir esto mismo seria un 422.
    corruptSetting('LOCALE_AVAILABLE', '["en"]');

    $response = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->get('/api/v1/settings')
        ->assertValidResponse(200);

    $byKey = [];

    foreach ((array) $response->json('data') as $row) {
        if (is_array($row) && is_string($row['key'] ?? null)) {
            $byKey[$row['key']] = $row;
        }
    }

    expect($byKey['LOCALE_DEFAULT']['value'])->toBe('en')
        ->and($response->json('meta.invalid_keys.0.key'))->toBe('LOCALE_DEFAULT')
        ->and($response->json('meta.invalid_keys.0.affects_worked_hours'))->toBeFalse();
})->group('RF-PD-01');

it('sigue rechazando por la API el mismo valor que al leer se descarta', function (): void {
    // Lectura tolerante, ESCRITURA ESTRICTA. Si el `PATCH` aceptara lo que la
    // lectura descarta, el cliente se iria convencido de haber configurado un
    // color que nadie va a aplicar.
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->patch('/api/v1/settings', ['settings' => ['BRANDING_ACCENT_COLOR' => 'rgb(17,24,39)']])
        ->assertValidResponse(422);
})->group('RF-PD-01');

it('deja de anotar la clave en cuanto se guarda un valor valido', function (): void {
    // El camino de salida que la documentacion de cliente promete: volver a
    // guardar la clave desde el panel repara la fila y la entrada desaparece.
    corruptSetting('BRANDING_ACCENT_COLOR', '"rgb(17,24,39)"');

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)
        ->patch('/api/v1/settings', ['settings' => ['BRANDING_ACCENT_COLOR' => '#0f172a']])
        ->assertValidResponse(200)
        ->assertJsonPath('meta.invalid_keys', []);
})->group('RF-PD-01');

it('devuelve las nueve claves como valor de serie tras una instalacion limpia', function (): void {
    // La migracion de contraccion retira la siembra de la tarea 1.3 que nadie ha
    // tocado: existia solo porque el adaptador antiguo exigia filas. Con ella
    // puesta, `source` mentiria en cuatro claves que nadie configuro, y el primer
    // asiento de auditoria diria `was_product_default: false` cuando lo cierto es
    // que regia el valor del producto.
    WorkforceFixtures::site();

    $response = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->get('/api/v1/settings')
        ->assertValidResponse(200);

    expect(DB::table('installation_settings')->count())->toBe(0)
        ->and($response->json('data.*.source'))->each->toBe('product_default');
})->group('RF-PD-01');
