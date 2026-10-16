<?php

declare(strict_types=1);

use App\Exceptions\ProblemDetails;
use Tests\Contract\Support\Contract;

/*
 * **EL `503` DE LOS PDF DECLARA SUS DOS `type`** (bloque 23 de la 2.2.1,
 * RF-IN-04, RL-05).
 *
 * Las tres rutas que componen PDF con Chromium comparten motor y, desde el tope
 * de composiciones simultaneas, comparten tambien su `503`: motor ausente
 * (`service-unavailable`) o motor ocupado (`pdf-rendering-busy`, con
 * `Retry-After`). El cliente TypeScript de las tres SPA se genera del contrato,
 * asi que el codigo tiene que emitir exactamente los `type` que alli constan.
 */

const PDF_RENDERING_UNAVAILABLE_ROUTES = [
    '/api/v1/me/export',
    '/api/v1/reports/period/export',
    '/api/v1/reports/adoption/export',
];

it('las tres rutas de PDF usan la misma respuesta 503', function (string $ruta): void {
    expect(Contract::value('paths', $ruta, 'get', 'responses', '503', '$ref'))
        ->toBe('#/components/responses/PdfRenderingUnavailable');
})->with(PDF_RENDERING_UNAVAILABLE_ROUTES)->group('RF-IN-04', 'RL-05');

it('declara el type del motor ocupado y el del motor ausente, los mismos que emite el codigo', function (): void {
    $ejemplo = static fn (string $nombre): mixed => Contract::value(
        'components', 'responses', 'PdfRenderingUnavailable', 'content', 'application/problem+json',
        'examples', $nombre, 'value', 'type',
    );

    expect($ejemplo('motor-ocupado'))->toBe(ProblemDetails::TYPE_PDF_RENDERING_BUSY)
        ->and($ejemplo('motor-ausente'))->toBe(ProblemDetails::TYPE_SERVICE_UNAVAILABLE)
        ->and(ProblemDetails::TYPE_PDF_RENDERING_BUSY)->toBe('urn:kronoqr:problem:pdf-rendering-busy');
})->group('RF-IN-04', 'RL-05');

it('declara Retry-After en el 503 de los PDF', function (): void {
    expect(Contract::value(
        'components', 'responses', 'PdfRenderingUnavailable', 'headers', 'Retry-After', 'schema', 'minimum',
    ))->toBe(1);
})->group('RF-IN-04');
