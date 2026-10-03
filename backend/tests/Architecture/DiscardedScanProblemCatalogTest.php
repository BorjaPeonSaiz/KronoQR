<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\ValueObject\DiscardedScan;
use Tests\Architecture\Support\Repo;

/*
 * RN-22 y F9 del dictamen del bloque 18: el catalogo cerrado de `problem` en el
 * `context` de la incidencia `discarded_scan` es una segunda lista de tipos de
 * problema. Cada entrada tiene que existir como `urn:kronoqr:problem:*` en el
 * contrato, o la incidencia nombraria un problema que el producto no emite.
 */

it('solo nombra en el contexto de un descarte tipos de problema que declara el contrato', function (): void {
    $contract = (string) file_get_contents(Repo::root().'/docs/api/openapi.yaml');

    $missing = array_values(array_filter(
        DiscardedScan::KNOWN_PROBLEMS,
        static fn (string $slug): bool => preg_match('/urn:kronoqr:problem:'.preg_quote($slug, '/').'(?![a-z0-9-])/', $contract) !== 1,
    ));

    expect(DiscardedScan::KNOWN_PROBLEMS)->not->toBeEmpty()
        ->and($missing)->toBe([], 'Tipos de problema que el contrato no declara: '.implode(', ', $missing));
})->group('RN-22', 'RQ-06');
