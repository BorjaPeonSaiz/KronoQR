<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Support\CanonicalJson;
use App\Modules\Product\Domain\ValueObject\DiagnosticsActor;
use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use App\Modules\Product\Domain\ValueObject\SealedDiagnosticsBundle;

/*
 * El sobre del paquete con datos personales como valor (ADR-020, enmienda del
 * 01-11-2026; RL-19, RF-PD-09): la cabecera en claro, sus datos asociados, lo
 * que dice de `personal_data` sin ningun valor, el presupuesto del texto en
 * claro y como se reconoce un sobre releido del disco.
 *
 * Sin criptografia: cifrar es de `SodiumDiagnosticsBundleSealer` y lo prueban
 * `DiagnosticsPathsAndSealingTest` (ida y vuelta con el abridor) y
 * `DiagnosticsSealingTest`.
 */

const SEALED_BUNDLE_UNIT_KEY_ID = '0123456789abcdef';

/**
 * @param  array<string, array<array-key, mixed>>  $sections
 */
function sealedBundleUnitPlain(array $sections, bool $anonymized = false, string $version = '2.3.0'): DiagnosticsBundle
{
    return DiagnosticsBundle::of(
        productVersion: $version,
        generatedAt: new DateTimeImmutable('2026-11-01T11:15:00+01:00'),
        anonymized: $anonymized,
        generatedBy: DiagnosticsActor::User,
        sections: $sections,
        maxBytes: 8 * 1024 * 1024,
    );
}

/**
 * @return array<string, array<array-key, mixed>>
 */
function sealedBundleUnitPersonal(): array
{
    return [
        'installation' => ['product_version' => '2.3.0'],
        'personal_data' => [
            'period_days' => 7,
            'employees' => ['total' => 2, 'truncated' => false, 'items' => [
                ['uuid' => 'u-1', 'full_name' => 'Lucia Ferrandiz', 'employee_code' => 'E-1'],
                'no es una fila',
                ['uuid' => 'u-2', 'full_name' => 'Bartolome Quintanilla', 'status' => 'active'],
            ]],
            'shift_entries' => ['total' => 0, 'truncated' => false, 'items' => []],
            'scan_events' => ['total' => 1, 'truncated' => false, 'items' => [['employee_uuid' => 'u-1']]],
            // Filas sin nombre de columna: las posiciones salen como texto.
            'incidents' => ['total' => 1, 'truncated' => false, 'items' => [['abierta', 'scan']]],
            // Sin `items`: no es una coleccion, no se describe.
            'devices' => ['status' => 'unavailable'],
        ],
    ];
}

function sealedBundleUnit(?DiagnosticsBundle $bundle = null): SealedDiagnosticsBundle
{
    $bundle ??= sealedBundleUnitPlain(sealedBundleUnitPersonal());

    return new SealedDiagnosticsBundle(
        manifest: $bundle->manifest,
        recipientKeyId: SEALED_BUNDLE_UNIT_KEY_ID,
        contents: SealedDiagnosticsBundle::contentsOf($bundle, 7, 1234),
        sealedKey: base64_encode('clave-sellada'),
        nonce: base64_encode(str_repeat("\1", 24)),
        ciphertext: base64_encode('texto/cifrado'),
    );
}

it('describe personal_data por colecciones: filas y columnas, sin ningun valor', function (): void {
    $contents = SealedDiagnosticsBundle::contentsOf(sealedBundleUnitPlain(sealedBundleUnitPersonal()), 7, 1234);

    expect($contents)->toBe([
        'period_days' => 7,
        'plaintext_bytes' => 1234,
        'status' => 'included',
        'collections' => [
            // Las columnas de todas las filas, sin repetir y en orden de
            // aparicion; una fila que no es un mapa cuenta como fila y no
            // aporta columnas.
            'employees' => ['rows' => 3, 'columns' => ['uuid', 'full_name', 'employee_code', 'status']],
            'shift_entries' => ['rows' => 0, 'columns' => []],
            'scan_events' => ['rows' => 1, 'columns' => ['employee_uuid']],
            'incidents' => ['rows' => 1, 'columns' => ['0', '1']],
        ],
    ])->and((string) json_encode($contents))->not->toContain('Lucia')
        ->and((string) json_encode($contents))->not->toContain('u-1');
})->group('RL-19', 'RF-PD-09');

it('dice omitted si la seccion no cupo, sin colecciones', function (): void {
    $bundle = sealedBundleUnitPlain([
        'installation' => ['product_version' => '2.3.0'],
        'personal_data' => ['status' => 'omitted', 'reason' => 'size_limit', 'bytes' => 9000],
    ]);

    expect(SealedDiagnosticsBundle::contentsOf($bundle, 31, 99))
        ->toBe(['period_days' => 31, 'plaintext_bytes' => 99, 'status' => 'omitted']);
})->group('RL-19', 'RF-PD-09');

it('dice unavailable si no hay seccion, si no es un mapa o si no tiene ninguna coleccion', function (array $sections): void {
    expect(SealedDiagnosticsBundle::contentsOf(sealedBundleUnitPlain($sections), 7, 10))
        ->toBe(['period_days' => 7, 'plaintext_bytes' => 10, 'status' => 'unavailable']);
})->with([
    'sin seccion' => [['installation' => ['v' => 1]]],
    'sin colecciones' => [['personal_data' => ['status' => 'unavailable', 'reason' => 'database_unavailable']]],
    'colecciones sin items' => [['personal_data' => ['employees' => ['total' => 0]]]],
])->group('RL-19', 'RF-PD-09');

it('la cabecera lleva formato, version, algoritmo, clave, manifiesto y contenido, en ese orden', function (): void {
    $sealed = sealedBundleUnit();
    $header = SealedDiagnosticsBundle::header($sealed->manifest, SEALED_BUNDLE_UNIT_KEY_ID, $sealed->contents);

    expect(array_keys($header))->toBe(['format', 'envelope_version', 'algorithm', 'recipient_key_id', 'manifest', 'contents'])
        ->and($header['format'])->toBe('kronoqr-diagnostics-sealed')
        ->and($header['envelope_version'])->toBe(1)
        ->and($header['algorithm'])->toBe('x25519-sealedbox+xchacha20poly1305-ietf')
        ->and($header['recipient_key_id'])->toBe(SEALED_BUNDLE_UNIT_KEY_ID)
        ->and($header['manifest'])->toBe($sealed->manifest->toArray())
        ->and($header['contents'])->toBe($sealed->contents);
})->group('RL-19', 'RF-PD-09');

it('los datos asociados son la cabecera canonica, sin los tres campos del sobre', function (): void {
    $sealed = sealedBundleUnit();
    $document = $sealed->toArray();
    $header = SealedDiagnosticsBundle::header($sealed->manifest, SEALED_BUNDLE_UNIT_KEY_ID, $sealed->contents);

    // Del documento entero releido —con el sobre dentro— sale lo mismo que de
    // la cabecera sola: es lo que hace el abridor.
    expect(SealedDiagnosticsBundle::associatedData($document))->toBe(CanonicalJson::encode($header))
        ->and(SealedDiagnosticsBundle::associatedData($header))->toBe(CanonicalJson::encode($header));

    // Cambiar cualquier campo de la cabecera cambia los datos asociados.
    foreach (['format', 'envelope_version', 'algorithm', 'recipient_key_id', 'manifest', 'contents'] as $field) {
        $altered = [...$document, $field => 'otro'];

        expect(SealedDiagnosticsBundle::associatedData($altered))
            ->not->toBe(SealedDiagnosticsBundle::associatedData($document), $field);
    }

    // Y cambiar el sobre, no: lo protege la etiqueta del cifrado.
    expect(SealedDiagnosticsBundle::associatedData([...$document, 'nonce' => 'x', 'sealed_key' => 'y', 'ciphertext' => 'z']))
        ->toBe(SealedDiagnosticsBundle::associatedData($document));
})->group('RL-19', 'RS-08');

it('el fichero es la cabecera y el sobre, indentado, sin escapar barras y con salto final', function (): void {
    $sealed = sealedBundleUnit();
    $json = $sealed->toJson();

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    expect(array_keys($decoded))->toBe([
        'format', 'envelope_version', 'algorithm', 'recipient_key_id', 'manifest', 'contents',
        'sealed_key', 'nonce', 'ciphertext',
    ])
        ->and($decoded['sealed_key'])->toBe(base64_encode('clave-sellada'))
        ->and($decoded['nonce'])->toBe(base64_encode(str_repeat("\1", 24)))
        ->and($decoded['ciphertext'])->toBe(base64_encode('texto/cifrado'))
        ->and($decoded)->toBe($sealed->toArray())
        ->and($json)->toEndWith("}\n")
        ->and($json)->toContain("\n    \"format\": \"kronoqr-diagnostics-sealed\",")
        ->and($json)->not->toContain('\/')
        ->and($sealed->sha256())->toBe(hash('sha256', $json))
        ->and($sealed->sha256())->not->toBe($sealed->manifest->sha256);
})->group('RL-19', 'RF-PD-09');

it('se llama como el paquete, con .sealed delante de la extension', function (): void {
    $sealed = sealedBundleUnit(sealedBundleUnitPlain(sealedBundleUnitPersonal(), version: '2.3.0 beta/1'));

    expect($sealed->fileName())->toBe('kronoqr-diagnostics-2.3.0_beta_1-20261101T101500Z.sealed.json')
        ->and($sealed->manifest->fileName())->toBe('kronoqr-diagnostics-2.3.0_beta_1-20261101T101500Z.json');
})->group('RF-PD-09');

it('reparte el tope: tres cuartos por el base64, menos el margen de la cabecera, y nunca menos de 1', function (): void {
    expect(SealedDiagnosticsBundle::plaintextBudget(8 * 1024 * 1024))->toBe(6 * 1024 * 1024 - 16 * 1024)
        ->and(SealedDiagnosticsBundle::plaintextBudget(400_000))->toBe(300_000 - 16_384)
        ->and(SealedDiagnosticsBundle::plaintextBudget(21_845))->toBe(1)
        ->and(SealedDiagnosticsBundle::plaintextBudget(21_848))->toBe(2)
        ->and(SealedDiagnosticsBundle::plaintextBudget(1000))->toBe(1);
})->group('RF-PD-09');

it('reconoce un sobre releido por su formato y su version, sin abrirlo', function (): void {
    $document = sealedBundleUnit()->toArray();

    expect(SealedDiagnosticsBundle::looksSealed($document))->toBeTrue()
        ->and(SealedDiagnosticsBundle::looksSealed(['format' => 'otro']))->toBeFalse()
        ->and(SealedDiagnosticsBundle::looksSealed(['manifest' => []]))->toBeFalse()
        ->and(SealedDiagnosticsBundle::wellFormedKeyId($document))->toBe(SEALED_BUNDLE_UNIT_KEY_ID);
})->group('RF-PD-09', 'RL-19');

it('no da por bien formado un sobre de otra version o con un campo que falta o no es texto', function (Closure $estropear): void {
    /** @var array<string, mixed> $document */
    $document = $estropear(sealedBundleUnit()->toArray());

    expect(SealedDiagnosticsBundle::wellFormedKeyId($document))->toBeNull();
})->with([
    'otro formato' => [static fn (array $d): array => [...$d, 'format' => 'kronoqr-diagnostics']],
    'otra version' => [static fn (array $d): array => [...$d, 'envelope_version' => 2]],
    'version como texto' => [static fn (array $d): array => [...$d, 'envelope_version' => '1']],
    'sin recipient_key_id' => [static fn (array $d): array => array_diff_key($d, ['recipient_key_id' => true])],
    'recipient_key_id numerico' => [static fn (array $d): array => [...$d, 'recipient_key_id' => 123]],
    'sin sealed_key' => [static fn (array $d): array => array_diff_key($d, ['sealed_key' => true])],
    'sin nonce' => [static fn (array $d): array => array_diff_key($d, ['nonce' => true])],
    'ciphertext nulo' => [static fn (array $d): array => [...$d, 'ciphertext' => null]],
])->group('RF-PD-09', 'RL-19');

it('el paquete con datos personales no se serializa en claro; solo su texto para cifrarlo', function (): void {
    $personal = sealedBundleUnitPlain(sealedBundleUnitPersonal());
    $anonymized = sealedBundleUnitPlain(['installation' => ['v' => 1]], anonymized: true);

    expect(fn (): string => $personal->toJson())
        ->toThrow(LogicException::class, 'Un paquete con datos personales no se entrega sin cifrar')
        ->and($personal->toPlaintextJson())->toContain('Lucia Ferrandiz')
        ->and($anonymized->toJson())->toBe($anonymized->toPlaintextJson());
})->group('RL-19');
