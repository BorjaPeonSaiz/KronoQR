<?php

declare(strict_types=1);

use App\Exceptions\ProblemDetails;
use Tests\Contract\Support\Contract;

/*
 * **EL `503` DE LAS TARJETAS Y DE LA HOJA DECLARA EL MOTOR OCUPADO**
 * (RF-QR-04, RL-05).
 *
 * Desde que pasan por el tope de Chromium de toda la instalacion, imprimir y
 * descargar la hoja pueden responder `pdf-rendering-busy` con `Retry-After`,
 * ademas del `service-unavailable` de siempre. El cliente del panel se genera
 * del contrato: el codigo tiene que emitir exactamente los `type` que alli
 * constan.
 */

const CREDENTIAL_DOCUMENT_UNAVAILABLE_ROUTES = [
    'lote' => ['/api/v1/credentials/print-batch', 'post'],
    'tarjeta' => ['/api/v1/credentials/{uuid}/print', 'post'],
    'hoja' => ['/api/v1/credentials/instructions-sheet', 'get'],
];

it('las tres rutas de tarjetas y hoja usan la misma respuesta 503', function (string $ruta, string $metodo): void {
    expect(Contract::value('paths', $ruta, $metodo, 'responses', '503', '$ref'))
        ->toBe('#/components/responses/CredentialDocumentUnavailable');
})->with(CREDENTIAL_DOCUMENT_UNAVAILABLE_ROUTES)->group('RF-QR-04', 'RL-05');

it('declara el type del motor ocupado y el de la instalacion sin configurar, los mismos que emite el codigo', function (): void {
    $ejemplo = static fn (string $nombre): mixed => Contract::value(
        'components', 'responses', 'CredentialDocumentUnavailable', 'content', 'application/problem+json',
        'examples', $nombre, 'value', 'type',
    );

    expect($ejemplo('motor-ocupado'))->toBe(ProblemDetails::TYPE_PDF_RENDERING_BUSY)
        ->and($ejemplo('sin-clave-de-firma'))->toBe(ProblemDetails::TYPE_SERVICE_UNAVAILABLE);
})->group('RF-QR-04', 'RL-05');

it('declara Retry-After en el 503 de tarjetas y hoja', function (): void {
    expect(Contract::value(
        'components', 'responses', 'CredentialDocumentUnavailable', 'headers', 'Retry-After', 'schema', 'minimum',
    ))->toBe(1);
})->group('RF-QR-04');
