<?php

declare(strict_types=1);

use App\Exceptions\ProblemDetails;
use App\Modules\Product\Domain\ValueObject\SealedDiagnosticsBundle;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Contract\Support\ComponentSchema;
use Tests\Contract\Support\Contract;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\DiagnosticsRecipient;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LOS DOS `200` Y EL `503` DE `POST /api/v1/diagnostics/bundle`** (ADR-020,
 * enmienda del 01-11-2026; RL-19, RF-PD-09).
 *
 * El `200` tiene dos tipos: el anonimizado, `application/json`, que valida
 * Spectator contra `DiagnosticsBundle` (`DiagnosticsEndpointTest`), y el que
 * lleva datos personales, `application/vnd.kronoqr.diagnostics-sealed+json`, que
 * Spectator no sabe distinguir —reduce todo `+json` a `application/json`— y por
 * eso se valida aqui contra `SealedDiagnosticsBundle` con `ComponentSchema`.
 *
 * El `503` sin clave publica de soporte tiene su propio `type` y **no** lleva
 * `Retry-After`, porque esperar no lo arregla; y no recoge nada ni escribe
 * ningun asiento. El cliente TypeScript del panel se genera del contrato: el
 * codigo tiene que emitir exactamente lo que alli consta.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    WorkforceFixtures::site();
    LicenseKeys::install();
});

function diagnosticsContractToken(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
}

it('declara los dos tipos del 200, con el mismo tipo que emite el codigo', function (): void {
    /** @var array<string, mixed> $content */
    $content = Contract::value('paths', '/api/v1/diagnostics/bundle', 'post', 'responses', '200', 'content');

    expect(array_keys($content))->toBe([SealedDiagnosticsBundle::MEDIA_TYPE, 'application/json'])
        ->and(Contract::value('paths', '/api/v1/diagnostics/bundle', 'post', 'responses', '503', '$ref'))
        ->toBe('#/components/responses/DiagnosticsBundleUnavailable');
})->group('RF-PD-09', 'RL-19');

it('declara el type de la instalacion sin clave de soporte, el mismo que emite el codigo', function (): void {
    $ejemplo = static fn (string $nombre): mixed => Contract::value(
        'components', 'responses', 'DiagnosticsBundleUnavailable', 'content', 'application/problem+json',
        'examples', $nombre, 'value', 'type',
    );

    expect($ejemplo('sin-clave-de-soporte'))->toBe(ProblemDetails::TYPE_DIAGNOSTICS_SEALING_UNAVAILABLE)
        ->and($ejemplo('dependencia-caida'))->toBe(ProblemDetails::TYPE_SERVICE_UNAVAILABLE)
        ->and(ProblemDetails::TYPE_DIAGNOSTICS_SEALING_UNAVAILABLE)
        ->toBe('urn:kronoqr:problem:diagnostics-sealing-unavailable');
})->group('RF-PD-09', 'RL-19');

it('el paquete con datos personales sale con su tipo, su nombre y la forma de SealedDiagnosticsBundle', function (): void {
    DiagnosticsRecipient::install();

    $response = Api::as(diagnosticsContractToken())
        ->post('/api/v1/diagnostics/bundle', ['include_personal_data' => true, 'period_days' => 7])
        ->assertValidRequest()
        ->assertStatus(200);

    $bytes = (string) $response->getContent();

    expect($response->headers->get('Content-Type'))->toStartWith(SealedDiagnosticsBundle::MEDIA_TYPE)
        ->and($response->headers->get('Content-Disposition'))->toMatch(
            '/^attachment; filename="kronoqr-diagnostics-[A-Za-z0-9._-]+-\d{8}T\d{6}Z\.sealed\.json"$/',
        )
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->has('Retry-After'))->toBeFalse()
        ->and(ComponentSchema::errors('SealedDiagnosticsBundle', $bytes))->toBe([]);
})->group('RF-PD-09', 'RL-19');

it('el anonimizado sigue validando contra DiagnosticsBundle, con o sin clave de soporte', function (bool $conClave): void {
    if ($conClave) {
        DiagnosticsRecipient::install();
    } else {
        DiagnosticsRecipient::uninstall();
    }

    $response = Api::as(diagnosticsContractToken())
        ->post('/api/v1/diagnostics/bundle')
        ->assertValidResponse(200);

    expect($response->headers->get('Content-Type'))->toStartWith('application/json')
        ->and(ComponentSchema::errors('DiagnosticsBundle', (string) $response->getContent()))->toBe([]);
})->with(['con clave' => true, 'sin clave' => false])->group('RF-PD-09', 'RL-19');

it('sin clave publica de soporte responde 503 con su type, sin Retry-After, sin recoger nada ni asiento', function (string $clave): void {
    config(['product.diagnostics_recipient_public_key' => $clave]);
    $antes = DB::table('audit_log')->count();

    $response = Api::as(diagnosticsContractToken())
        ->post('/api/v1/diagnostics/bundle', ['include_personal_data' => true, 'period_days' => 7])
        ->assertValidRequest()
        ->assertValidResponse(503);

    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::TYPE_DIAGNOSTICS_SEALING_UNAVAILABLE)
        ->and($response->json('status'))->toBe(503)
        ->and($response->json('detail'))->toContain('anonimizado')
        ->and($response->headers->has('Retry-After'))->toBeFalse()
        ->and(DB::table('audit_log')->count())->toBe($antes);

    // Ningun valor de la clave en la respuesta: dice que falta, no cual es.
    if ($clave !== '') {
        expect((string) $response->getContent())->not->toContain($clave);
    }
})->with([
    'vacia' => '',
    'demasiado corta' => str_repeat('ab', 16),
    'no hexadecimal' => str_repeat('zz', 32),
    'de ceros' => str_repeat('00', 32),
])->group('RF-PD-09', 'RL-19');
