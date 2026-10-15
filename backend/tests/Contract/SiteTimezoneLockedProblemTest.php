<?php

declare(strict_types=1);

use App\Exceptions\ProblemDetails;
use Tests\Contract\Support\Contract;

/*
 * **«LA ZONA YA ESTA FIJA» TIENE SU PROPIO `type`** (RN-23, ADR-056).
 *
 * `PATCH /site` devuelve dos `409`: el del nombre ya usado, que se corrige en el
 * formulario, y el de la zona fija, que obliga a releer el centro. El contrato
 * los separa con `SiteConflict`, y el codigo tiene que emitir exactamente el
 * `type` que el contrato declara: el cliente TypeScript de las tres SPA se
 * genera de ahi.
 */

it('declara el type de la zona fija en la respuesta de conflicto del centro', function (): void {
    $tipo = Contract::value(
        'components', 'responses', 'SiteConflict', 'content', 'application/problem+json',
        'examples', 'zona-fija', 'value', 'type',
    );

    expect($tipo)->toBe(ProblemDetails::TYPE_SITE_TIME_ZONE_LOCKED)
        ->and(ProblemDetails::TYPE_SITE_TIME_ZONE_LOCKED)->toBe('urn:kronoqr:problem:site-time-zone-locked');
})->group('RN-23');

it('la usa el 409 de la modificacion del centro', function (): void {
    expect(Contract::value('paths', '/api/v1/site', 'patch', 'responses', '409', '$ref'))
        ->toBe('#/components/responses/SiteConflict');
})->group('RN-23');

it('exige timezone_locked en el centro que publica la API', function (): void {
    expect(Contract::value('components', 'schemas', 'Site', 'required'))->toContain('timezone_locked')
        ->and(Contract::value('components', 'schemas', 'Site', 'properties', 'timezone_locked', 'type'))->toBe('boolean');
})->group('RN-23');
