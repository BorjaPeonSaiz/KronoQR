<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\CanonicalJson;
use App\Modules\Product\Domain\ValueObject\DiagnosticsActor;
use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use App\Modules\Product\Domain\ValueObject\DiagnosticsDigestCheck;

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

/**
 * La huella esperada: el documento entero con el manifiesto sin su `sha256`
 * (PR16), escrita aparte de la implementacion para que no se compruebe a si
 * misma.
 */
function diagnosticsBundleUnitDigest(DiagnosticsBundle $bundle): string
{
    $manifest = $bundle->manifest->toArray();
    unset($manifest['sha256']);

    return CanonicalJson::digest(['manifest' => $manifest, ...$bundle->sections]);
}

/**
 * Un paquete sellado al que se le cambia a mano algo del manifiesto (PR16),
 * sin recalcular la huella.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function diagnosticsBundleUnitTampered(array $changes, bool $anonymized = true): array
{
    $bundle = DiagnosticsBundle::of(
        productVersion: '2.2.1',
        generatedAt: new DateTimeImmutable('2026-06-15T09:00:00+02:00'),
        anonymized: $anonymized,
        generatedBy: DiagnosticsActor::User,
        sections: diagnosticsBundleUnitSections(),
        maxBytes: DIAGNOSTICS_BUNDLE_UNIT_MAX,
    );

    return ['manifest' => [...$bundle->manifest->toArray(), ...$changes], ...$bundle->sections];
}

it('sella el manifiesto con la huella canonica del documento, manifiesto incluido', function (): void {
    $bundle = diagnosticsBundleUnit(diagnosticsBundleUnitSections());

    expect($bundle->manifest->sha256)->toBe(diagnosticsBundleUnitDigest($bundle))
        // Ya no es la de las secciones solas (PR16).
        ->and($bundle->manifest->sha256)->not->toBe(CanonicalJson::digest(diagnosticsBundleUnitSections()))
        ->and($bundle->manifest->toUnsealedArray())->not->toHaveKey('sha256')
        ->and($bundle->manifest->sections)->toBe(['installation', 'kiosks', 'metrics', 'audit'])
        ->and($bundle->manifest->toArray())->toMatchArray([
            'schema_version' => 2,
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
    // PR16: el manifiesto tambien esta dentro de la huella. El primero es el
    // caso reproducido: un paquete con datos personales que se hace pasar por
    // anonimo.
    'anonymized cambiado a mano' => [diagnosticsBundleUnitTampered(['anonymized' => true], anonymized: false)],
    'version cambiada' => [diagnosticsBundleUnitTampered(['product_version' => '9.9.9'])],
    'instante cambiado' => [diagnosticsBundleUnitTampered(['generated_at' => '2026-06-16T07:00:00.000000Z'])],
    'actor cambiado' => [diagnosticsBundleUnitTampered(['generated_by' => 'support_grant'])],
    'lista de secciones recortada' => [diagnosticsBundleUnitTampered(['sections' => ['installation']])],
    'version del esquema desconocida' => [diagnosticsBundleUnitTampered(['schema_version' => 3])],
    'rebajado a la version 1 para eludir el manifiesto' => [diagnosticsBundleUnitTampered(['schema_version' => 1])],
    'sin manifiesto' => [['installation' => []]],
    'manifiesto que no es objeto' => [['manifest' => 'x', 'installation' => []]],
    'huella que no es texto' => [['manifest' => ['sha256' => 7], 'installation' => []]],
])->group('RF-PD-09');

/**
 * Un paquete de `schema_version: 1`, como los de la 2.2.x: la huella cubre
 * solo las secciones. Escrito a mano, con el algoritmo de entonces.
 *
 * @param  array<string, mixed>  $manifestChanges
 * @param  array<string, array<array-key, mixed>>  $sectionChanges
 * @return array<string, mixed>
 */
function diagnosticsBundleUnitVersionOne(array $manifestChanges = [], array $sectionChanges = []): array
{
    $sections = diagnosticsBundleUnitSections();

    return [
        'manifest' => [
            'schema_version' => 1,
            'product_version' => '2.2.1',
            'generated_at' => '2026-06-15T07:00:00.000000Z',
            'anonymized' => true,
            'generated_by' => 'console',
            'sections' => array_keys($sections),
            'sha256' => CanonicalJson::digest($sections),
            ...$manifestChanges,
        ],
        ...$sections,
        ...$sectionChanges,
    ];
}

it('dice si la huella cubre el manifiesto: la version 2 si, la 1 solo las secciones (PR16)', function (): void {
    expect(DiagnosticsBundle::checkDigest(diagnosticsBundleUnit(diagnosticsBundleUnitSections())->toArray()))
        ->toBe(DiagnosticsDigestCheck::Intact)
        ->and(DiagnosticsBundle::checkDigest(diagnosticsBundleUnitVersionOne()))
        ->toBe(DiagnosticsDigestCheck::IntactWithoutManifest)
        ->and(DiagnosticsBundle::digestMatches(diagnosticsBundleUnitVersionOne()))->toBeTrue()
        ->and(DiagnosticsDigestCheck::Intact->matches())->toBeTrue()
        ->and(DiagnosticsDigestCheck::IntactWithoutManifest->matches())->toBeTrue()
        ->and(DiagnosticsDigestCheck::Mismatch->matches())->toBeFalse();
})->group('RF-PD-09');

it('un paquete de la version 1 con una seccion cambiada no verifica', function (): void {
    expect(DiagnosticsBundle::checkDigest(diagnosticsBundleUnitVersionOne(sectionChanges: ['installation' => ['product_version' => '9.9.9']])))
        ->toBe(DiagnosticsDigestCheck::Mismatch);
})->group('RF-PD-09');

it('en la version 1 la cabecera no esta cubierta, y por eso se presenta aparte (PR16)', function (): void {
    // Es la limitacion que la version 2 cierra: un `anonymized` cambiado a mano
    // en un paquete viejo sigue verificando, y `--verify` lo dice.
    expect(DiagnosticsBundle::checkDigest(diagnosticsBundleUnitVersionOne(['anonymized' => false])))
        ->toBe(DiagnosticsDigestCheck::IntactWithoutManifest);
})->group('RF-PD-09');

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
        ->and($bundle->manifest->sha256)->toBe(diagnosticsBundleUnitDigest($bundle));

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
        ->and($pending->manifest->sha256)->toBe(diagnosticsBundleUnitDigest($pending))
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
