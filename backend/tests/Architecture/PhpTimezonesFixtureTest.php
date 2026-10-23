<?php

declare(strict_types=1);

use Tests\Architecture\Support\Repo;

/*
 * El volcado de zonas horarias que usa la prueba del panel sigue siendo el de
 * este PHP (V21-1-UX-1, tarea 16.6).
 *
 * El `datalist` de zonas del panel (`frontend-admin/src/shared/ui/timezoneOptions.ts`)
 * ofrece lo que da `Intl.supportedValuesOf('timeZone')` del navegador, y el
 * servidor valida contra `DateTimeZone::listIdentifiers()`. Su prueba unitaria
 * compara las dos listas con un volcado de la segunda, porque Vitest no puede
 * llamar a PHP: `frontend-admin/tests/unit/fixtures/php-timezones.json`.
 *
 * Un volcado de una base tz vieja haria pasar en verde una zona que el servidor
 * ya no acepta, o al reves. Esta prueba corre en la imagen del producto y avisa
 * en cuanto la version de PHP trae otra lista. Para regenerarlo:
 *
 *     docker exec kronoqr-app php -r 'echo json_encode(DateTimeZone::listIdentifiers(), JSON_PRETTY_PRINT), "\n";' \
 *         > frontend-admin/tests/unit/fixtures/php-timezones.json
 *
 * Se comparan las listas decodificadas, no los bytes: la sangria del JSON da
 * igual.
 */

const PHP_TIMEZONES_FIXTURE = 'frontend-admin/tests/unit/fixtures/php-timezones.json';

it('coincide con las zonas que acepta el PHP de la imagen', function (): void {
    $fixture = json_decode(Repo::contents(PHP_TIMEZONES_FIXTURE), true, flags: JSON_THROW_ON_ERROR);

    expect($fixture)->toBe(
        DateTimeZone::listIdentifiers(),
        PHP_TIMEZONES_FIXTURE.' esta desfasado respecto a PHP '.PHP_VERSION
        .'. Regeneralo con la orden de la cabecera de esta prueba.',
    );
})->group('RF-PD-03');
