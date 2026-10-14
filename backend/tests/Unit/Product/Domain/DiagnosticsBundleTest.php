<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\CanonicalJson;
use App\Modules\Product\Domain\ValueObject\DiagnosticsActor;
use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;

/*
 * El paquete de diagnostico como valor (RF-PD-09, ADR-020): sellado, recorte por
 * tamaño en su orden fijo, verificacion de la huella y la constancia de asiento
 * pendiente de la enmienda del 14-10-2026 (V4-PL-1).
 *
 * Sin base de datos ni ficheros: solo el documento y su huella.
 */

const DIAGNOSTICS_BUNDLE_UNIT_MAX = 8 * 1024 * 1024;

/**
 * @param  array<string, array<array-key, mixed>>  $sections
 */
function diagnosticsBundleUnit(array $sections, int $maxBytes = DIAGNOSTICS_BUNDLE_UNIT_MAX): DiagnosticsBundle
{
    return DiagnosticsBundle::of(
        productVersion: '2.2.1',
        generatedAt: new DateTimeImmutable('2026-06-15T09:00:00+02:00'),
        anonymized: true,
        generatedBy: DiagnosticsActor::Console,
        sections: $sections,
        maxBytes: $maxBytes,
    );
}

/**
 * @return array<string, array<array-key, mixed>>
 */
function diagnosticsBundleUnitSections(): array
{
    return [
        'installation' => ['product_version' => '2.2.1'],
        'kiosks' => [['uuid' => 'a'], ['uuid' => 'b']],
        'metrics' => ['ratio' => 2.0],
        'audit' => ['by_family' => ['x' => 1]],
    ];
}

it('sella el manifiesto con la huella canonica de las secciones', function (): void {
    $bundle = diagnosticsBundleUnit(diagnosticsBundleUnitSections());

    expect($bundle->manifest->sha256)->toBe(CanonicalJson::digest(diagnosticsBundleUnitSections()))
        ->and($bundle->manifest->sections)->toBe(['installation', 'kiosks', 'metrics', 'audit'])
        ->and($bundle->manifest->toArray())->toMatchArray([
            'schema_version' => 1,
            'product_version' => '2.2.1',
            'generated_at' => '2026-06-15T07:00:00.000000Z',
            'anonymized' => true,
            'generated_by' => 'console',
        ])
        ->and($bundle->toArray())->toBe(['manifest' => $bundle->manifest->toArray(), ...diagnosticsBundleUnitSections()])
        ->and($bundle->generationEntryPending())->toBeFalse();
})->group('RF-PD-09');

it('se escribe indentado, con salto final y conservando 2.0, y verifica al releerlo', function (): void {
    $json = diagnosticsBundleUnit(diagnosticsBundleUnitSections())->toJson();

    /** @var array<string, mixed> $document */
    $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    expect($json)->toEndWith("}\n")
        ->and($json)->toContain("\n    \"manifest\": {")
        ->and($json)->toContain('"ratio": 2.0')
        ->and(DiagnosticsBundle::digestMatches($document))->toBeTrue();
})->group('RF-PD-09');

it('la huella denuncia un documento alterado, sin manifiesto o sin huella', function (array $document): void {
    expect(DiagnosticsBundle::digestMatches($document))->toBeFalse();
})->with([
    'una seccion cambiada' => [(static function (): array {
        $document = diagnosticsBundleUnit(diagnosticsBundleUnitSections())->toArray();
        $document['installation'] = ['product_version' => '9.9.9'];

        return $document;
    })()],
    'sin manifiesto' => [['installation' => []]],
    'manifiesto que no es objeto' => [['manifest' => 'x', 'installation' => []]],
    'huella que no es texto' => [['manifest' => ['sha256' => 7], 'installation' => []]],
])->group('RF-PD-09');

it('si no cabe, sacrifica en su orden y deja constancia del tamaño, sin tocar las seis de siempre', function (): void {
    $big = str_repeat('x', 4000);
    $sections = [
        'installation' => ['v' => $big],
        'audit' => ['v' => $big],
        'metrics' => ['v' => $big],
        'kiosks' => [['v' => $big]],
    ];

    // Cabe todo menos una seccion grande: se va la primera del orden, `audit`.
    $bundle = diagnosticsBundleUnit($sections, 3 * 4100);

    expect($bundle->sections['audit'])->toBe([
        'status' => 'omitted',
        'reason' => 'size_limit',
        'bytes' => strlen(CanonicalJson::encode(['v' => $big])),
    ])
        ->and($bundle->sections['metrics'])->toBe(['v' => $big])
        ->and($bundle->sections['kiosks'])->toBe([['v' => $big]])
        ->and($bundle->sections['installation'])->toBe(['v' => $big])
        ->and($bundle->manifest->sha256)->toBe(CanonicalJson::digest($bundle->sections));

    // Con un tope minimo se van todas las sacrificables y `installation` queda.
    $tiny = diagnosticsBundleUnit($sections, 10);

    expect($tiny->sections['installation'])->toBe(['v' => $big])
        ->and($tiny->sections['kiosks'])->toMatchArray(['status' => 'omitted'])
        ->and($tiny->sections['metrics'])->toMatchArray(['status' => 'omitted']);
})->group('RF-PD-09');

it('lo que cabe justo en el tope no se recorta', function (): void {
    $sections = diagnosticsBundleUnitSections();

    expect(diagnosticsBundleUnit($sections, strlen(CanonicalJson::encode($sections)))->sections)->toBe($sections)
        ->and(diagnosticsBundleUnit($sections, strlen(CanonicalJson::encode($sections)) - 1)->sections['audit'])
        ->toMatchArray(['status' => 'omitted']);
})->group('RF-PD-09');

it('con el asiento pendiente vuelve a sellar: la constancia va en audit y dentro de la huella (V4-PL-1)', function (): void {
    $bundle = diagnosticsBundleUnit(diagnosticsBundleUnitSections());
    $pending = $bundle->withGenerationEntryPending('database_unavailable');

    expect($pending->generationEntryPending())->toBeTrue()
        ->and($pending->sections['audit'])->toBe([
            'by_family' => ['x' => 1],
            'generation_entry' => ['status' => 'pending', 'reason' => 'database_unavailable'],
        ])
        ->and($pending->manifest->sha256)->not->toBe($bundle->manifest->sha256)
        ->and($pending->manifest->sha256)->toBe(CanonicalJson::digest($pending->sections))
        ->and(DiagnosticsBundle::digestMatches($pending->toArray()))->toBeTrue()
        ->and($pending->manifest->sections)->toBe($bundle->manifest->sections)
        ->and($pending->manifest->generatedAt)->toBe($bundle->manifest->generatedAt)
        ->and($pending->manifest->productVersion)->toBe('2.2.1')
        ->and($pending->manifest->anonymized)->toBeTrue()
        ->and($pending->manifest->generatedBy)->toBe(DiagnosticsActor::Console);

    // Quitar la marca sin recalcular la huella hace fallar la verificacion.
    $stripped = $pending->toArray();
    /** @var array<string, mixed> $audit */
    $audit = $stripped['audit'];
    unset($audit['generation_entry']);
    $stripped['audit'] = $audit;

    expect(DiagnosticsBundle::digestMatches($stripped))->toBeFalse();
})->group('RF-PD-09', 'RL-04');

it('añade la seccion audit si no estaba, y respeta la que se sacrifico por tamaño (V4-PL-1)', function (): void {
    $sinAudit = diagnosticsBundleUnit(['installation' => ['v' => 1]])->withGenerationEntryPending('database_unavailable');

    expect($sinAudit->sections['audit'])->toBe(['generation_entry' => ['status' => 'pending', 'reason' => 'database_unavailable']])
        ->and($sinAudit->manifest->sections)->toBe(['installation', 'audit']);

    $omitida = diagnosticsBundleUnit(['installation' => ['v' => 1], 'audit' => ['v' => str_repeat('x', 500)]], 100)
        ->withGenerationEntryPending('database_unavailable');

    expect($omitida->sections['audit'])->toMatchArray([
        'status' => 'omitted',
        'reason' => 'size_limit',
        'generation_entry' => ['status' => 'pending', 'reason' => 'database_unavailable'],
    ])
        ->and($omitida->generationEntryPending())->toBeTrue()
        ->and(DiagnosticsBundle::digestMatches($omitida->toArray()))->toBeTrue();
})->group('RF-PD-09', 'RL-04');

it('lista las secciones del contrato sin el manifiesto', function (): void {
    expect(DiagnosticsBundle::SECTIONS)->toBe([
        'installation', 'configuration', 'services', 'doctor', 'license', 'kiosks',
        'error_events', 'metrics', 'updates', 'audit', 'personal_data',
    ]);
})->group('RF-PD-09');
