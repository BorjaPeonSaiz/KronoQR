<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Port\CardRenderer;
use App\Modules\Identity\Domain\ValueObject\CardFormat;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\FakeCardRenderer;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `POST /credentials/{uuid}/print` y `POST /credentials/print-batch` contra el
 * contrato (RF-QR-04, ADR-034).
 *
 * Cerraba el hueco de nivel de RF-QR-04 en `TestLevelGateTest`: ninguna prueba
 * validaba contra `openapi.yaml` la respuesta de la impresion —las de la
 * disposicion de la tarjeta van contra el renderizador, no contra el
 * endpoint—. El cuerpo es un PDF binario, pero lo que el panel lee de la
 * respuesta no lo es: el codigo, `Content-Type`, `Cache-Control: no-store` y
 * `X-Kronoqr-Printed-Count`, con el que dice «se han impreso 40» sin abrir el
 * documento. Eso es lo que se valida.
 *
 * Sin Chromium: los bytes los pone {@see FakeCardRenderer}; ruta, policy,
 * acuñado y respuesta son los de verdad.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * Un empleado con su credencial emitida y pendiente de imprimir.
 */
function credencialPendienteDeImprimir(string $token): string
{
    $uuid = Api::as($token)
        ->post('/api/v1/credentials', ['employee_uuid' => WorkforceFixtures::employee(WorkforceFixtures::site())])
        ->assertStatus(201)
        ->json('uuid');

    expect($uuid)->toBeString();

    /** @var string $uuid */
    return $uuid;
}

function tokenDeRrhhParaImprimir(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
}

it('imprime una tarjeta como un PDF que cumple el contrato y no se cachea', function (): void {
    $renderer = new FakeCardRenderer;
    app()->instance(CardRenderer::class, $renderer);
    $token = tokenDeRrhhParaImprimir();
    $credencial = credencialPendienteDeImprimir($token);

    $respuesta = Api::as($token)->post('/api/v1/credentials/'.$credencial.'/print')
        ->assertValidRequest()
        ->assertValidResponse(200);

    expect($respuesta->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($respuesta->headers->get('X-Kronoqr-Printed-Count'))->toBe('1')
        ->and($respuesta->headers->get('Cache-Control'))->toContain('no-store')
        ->and($respuesta->getContent())->toBe(FakeCardRenderer::PDF_BYTES)
        ->and($renderer->formats)->toBe([CardFormat::CARD]);
})->group('RF-QR-04');

it('imprime el lote pendiente en una sola hoja A4 que cumple el contrato, y la segunda pasada responde 204', function (): void {
    $renderer = new FakeCardRenderer;
    app()->instance(CardRenderer::class, $renderer);
    $token = tokenDeRrhhParaImprimir();
    credencialPendienteDeImprimir($token);
    credencialPendienteDeImprimir($token);

    // Sin `assertValidRequest()`: el cuerpo es opcional y aqui no se envia, y
    // Spectator compara esa ausencia con el esquema de objeto como si fuera null.
    $lote = Api::as($token)->post('/api/v1/credentials/print-batch')
        ->assertValidResponse(200);

    expect($lote->headers->get('X-Kronoqr-Printed-Count'))->toBe('2')
        ->and($lote->headers->get('Cache-Control'))->toContain('no-store')
        ->and($renderer->formats)->toBe([CardFormat::SHEET])
        ->and($renderer->renderedBatches[0])->toHaveCount(2);

    // ADR-034: no hay reimpresion, y la idempotencia del lote es esta.
    Api::as($token)->post('/api/v1/credentials/print-batch')
        ->assertValidResponse(204);
})->group('RF-QR-04');
