<?php

declare(strict_types=1);

use App\Modules\Product\Infrastructure\Diagnostics\Probe\RejectionFloorBudgetProbe;
use Tests\Architecture\Support\Repo;

/*
 * EL CORTE DEL QUIOSCO QUE USA `product:doctor` ES EL DE LA PWA (RS-03, A-13;
 * revision de la 33.6).
 *
 * `RejectionFloorBudgetProbe` avisa cuando un lote de rechazos se acerca al
 * corte de cada peticion del quiosco, que va compilado en
 * `frontend-kiosk/src/shared/api/client.ts` (`DEFAULT_TIMEOUT_MS`) y no es
 * configuracion. Si alguien cambia uno y no el otro, la sonda juzgaria contra
 * un corte que ya no existe.
 */

it('el corte de la sonda del suelo de rechazos es el DEFAULT_TIMEOUT_MS del cliente del quiosco', function (): void {
    $client = (string) file_get_contents(Repo::file('frontend-kiosk/src/shared/api/client.ts'));

    expect(preg_match('/^const DEFAULT_TIMEOUT_MS = ([\d_]+)$/m', $client, $match))->toBe(1, 'No se encuentra DEFAULT_TIMEOUT_MS en el cliente del quiosco.')
        ->and((int) str_replace('_', '', $match[1] ?? ''))->toBe(RejectionFloorBudgetProbe::KIOSK_REQUEST_TIMEOUT_MS);
})->group('RS-03');
