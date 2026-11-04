<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\DiagnosticsActor;
use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use App\Modules\Product\Infrastructure\Diagnostics\BundleDoctorFindingFilter;
use App\Modules\Product\Infrastructure\Diagnostics\JsonDiagnosticsBundleWriter;
use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\Repo;

/*
 * EL PAQUETE CON DATOS PERSONALES SALE CIFRADO Y EL ANONIMIZADO SIN RUTAS
 * (ADR-020, enmienda del 01-11-2026; RL-19, RF-PD-09, RS-08).
 *
 * Cinco propiedades que no puede romper un cambio despistado:
 *
 * 1. Toda clave de `params` y `details` que emite una sonda de `doctor` esta
 *    clasificada en `BundleDoctorFindingFilter`. Una sin clasificar no filtra
 *    nada —se descarta—, pero el dato se pierde en el paquete sin que nadie lo
 *    note; como el vocabulario de ADR-048, se extrae del codigo.
 * 2. Nada en el producto abre un sobre: la instalacion solo tiene la publica.
 * 3. Solo el sellador pide el texto en claro de un paquete con datos personales.
 * 4. El escritor y el controlador no entregan un paquete con datos personales
 *    sin sellar.
 * 5. El abridor del fabricante usa la forma del sobre del dominio
 *    (`DiagnosticsEnvelope`), no una copia.
 */

/** El codigo de un fichero PHP sin comentarios. */
function diagnosticsSealingCode(string $file): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/**
 * Los ficheros de donde salen las claves de `params` y `details` de `doctor`:
 * las sondas, lo que ellas vuelcan tal cual en `details` y la red de
 * `RunDoctorHandler` para una sonda que revienta.
 *
 * @return list<string>
 */
function diagnosticsSealingProbeSources(): array
{
    $app = dirname(ModuleTree::root());
    $diagnostics = $app.'/Modules/Product/Infrastructure/Diagnostics';
    $sources = array_values(array_filter(
        array_map(static fn (string $name): string => $diagnostics.'/Probe/'.$name, scandir($diagnostics.'/Probe') ?: []),
        static fn (string $file): bool => str_ends_with($file, '.php'),
    ));

    return [
        ...$sources,
        $diagnostics.'/ServiceInspector.php',
        $diagnostics.'/HorizonQueueSupervisors.php',
        $app.'/Modules/Product/Application/UseCase/RunDoctorHandler.php',
    ];
}

/**
 * Claves literales que aparecen en esos ficheros y NO son claves de un
 * hallazgo: el contexto TLS de `stream_context_create`, los brazos de un
 * `match` y las claves internas de `ServiceInspector` que no llegan a
 * `details`. Si una de ellas llegara a emitirse, se descartaria: falla cerrado.
 */
const DIAGNOSTICS_SEALING_NOT_EMITTED = [
    // El contexto TLS de `TlsProbe`, no un detalle.
    'ssl', 'capture_peer_cert', 'verify_peer', 'verify_peer_name', 'SNI_enabled', 'peer_name',
    // Brazos de `match` de `DatabaseProbe` y `EdgeNetworksProbe`.
    'verified', 'no_anchor', 'mismatch', 'not_provided', 'sample', 'public',
];

it('toda clave de params y details que emite una sonda de doctor esta clasificada', function (): void {
    $unclassified = [];

    foreach (diagnosticsSealingProbeSources() as $file) {
        preg_match_all("/'([A-Za-z_][A-Za-z0-9_]*)'\\s*=>/", diagnosticsSealingCode($file), $matches);

        foreach ($matches[1] as $key) {
            if (BundleDoctorFindingFilter::classify($key) === null && ! in_array($key, DIAGNOSTICS_SEALING_NOT_EMITTED, true)) {
                $unclassified[$key] = $key.' ('.basename($file).')';
            }
        }
    }

    ksort($unclassified);

    expect(array_values($unclassified))->toBe([], 'Claves de doctor sin clasificar en BundleDoctorFindingFilter. '
        .'Dentro del paquete se descartan: clasificalas (PASS si es una cifra o un enumerado del producto, '
        .'LOCATION si es una ruta, HOST si es el nombre de APP_URL) o, si no se emiten, anadelas a '
        .'DIAGNOSTICS_SEALING_NOT_EMITTED.');
})->group('RL-19', 'RF-PD-09');

it('no deja en la lista de no emitidas una clave que ya esta clasificada', function (): void {
    foreach (DIAGNOSTICS_SEALING_NOT_EMITTED as $key) {
        expect(BundleDoctorFindingFilter::classify($key))->toBeNull($key.' esta clasificada y en la lista de no emitidas');
    }
})->group('RL-19', 'RF-PD-09');

it('nada en el producto abre un sobre: la instalacion solo tiene la clave publica', function (): void {
    $openers = [
        'sodium_crypto_box_seal_open',
        'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt',
        'sodium_crypto_box_open',
        'sodium_crypto_secretbox_open',
    ];
    $product = dirname(ModuleTree::root()).'/Modules/Product';
    $found = [];

    foreach (ModuleTree::phpFilesUnder($product) as $file) {
        $code = diagnosticsSealingCode($file);

        foreach ($openers as $opener) {
            if (str_contains($code, $opener)) {
                $found[] = basename($file).': '.$opener;
            }
        }
    }

    expect($found)->toBe([]);
})->group('RL-19', 'RS-08');

it('solo el sellador pide el texto en claro de un paquete con datos personales', function (): void {
    $app = dirname(ModuleTree::root());
    $callers = [];

    foreach (ModuleTree::phpFilesUnder($app) as $file) {
        if (str_contains(diagnosticsSealingCode($file), '->toPlaintextJson(')) {
            $callers[] = substr($file, strlen($app) + 1);
        }
    }

    sort($callers);

    expect($callers)->toBe([
        'Modules/Product/Domain/ValueObject/DiagnosticsBundle.php',
        'Modules/Product/Infrastructure/Diagnostics/SodiumDiagnosticsBundleSealer.php',
    ]);
})->group('RL-19');

it('un paquete con datos personales sin sellar no se serializa ni se escribe', function (): void {
    $bundle = DiagnosticsBundle::of(
        productVersion: '2.3.0',
        generatedAt: new DateTimeImmutable('2026-11-01T10:15:00Z'),
        anonymized: false,
        generatedBy: DiagnosticsActor::User,
        sections: ['personal_data' => ['employees' => ['total' => 1, 'truncated' => false, 'items' => [['full_name' => 'Lucia']]]]],
        maxBytes: 1024 * 1024,
    );
    $directory = sys_get_temp_dir().'/kq-sealing-'.bin2hex(random_bytes(6));

    expect(fn (): string => $bundle->toJson())->toThrow(LogicException::class)
        ->and(fn (): mixed => (new JsonDiagnosticsBundleWriter($directory))->stage($bundle))->toThrow(LogicException::class)
        // Lanza antes de tocar el disco: ni el directorio se crea.
        ->and(is_dir($directory))->toBeFalse();
})->group('RL-19');

it('el controlador sella o se niega: nunca entrega en claro un paquete con datos personales', function (): void {
    $code = diagnosticsSealingCode(Repo::file('backend/app/Modules/Product/Http/Controller/DiagnosticsController.php'));

    expect($code)->toContain('instanceof SealedDiagnosticsBundle')
        ->toContain('SealedDiagnosticsBundle::MEDIA_TYPE')
        ->toContain('! $bundle->manifest->anonymized')
        ->toContain('throw new LogicException');
})->group('RL-19');

it('el abridor del fabricante no entra en ninguna imagen', function (): void {
    $ignored = array_map('trim', explode("\n", Repo::contents('.dockerignore')));

    expect($ignored)->toContain('tools/')
        ->and(is_file(Repo::file('tools/diagnostics-opener/open.php')))->toBeTrue()
        ->and(is_dir(Repo::file('backend/tools/diagnostics-opener')))->toBeFalse();
})->group('RS-08');

it('la puerta de release falla sin la clave publica de soporte compilada (RS-08)', function (): void {
    $pattern = "env\\('DIAGNOSTICS_RECIPIENT_PUBLIC_KEY', '[0-9a-fA-F]{64}'\\)";
    $regex = '/'.$pattern.'/';

    // La puerta busca exactamente esta forma en config/product.php, y la clave
    // va en el segundo argumento de `env()`, como la de licencias.
    expect(Repo::contents('Makefile'))->toContain('grep -qE "'.$pattern.'" backend/config/product.php')
        ->and(Repo::contents('backend/config/product.php'))->toContain("env('DIAGNOSTICS_RECIPIENT_PUBLIC_KEY', '")
        // Rechaza un valor de serie vacio, que es el de un arbol que se quedo sin
        // la clave, y acepta una publica de 64 hexadecimales.
        ->and(preg_match($regex, "env('DIAGNOSTICS_RECIPIENT_PUBLIC_KEY', '')"))->toBe(0)
        ->and(preg_match($regex, "env('DIAGNOSTICS_RECIPIENT_PUBLIC_KEY', '".str_repeat('ab', 32)."')"))->toBe(1);

    // Es otro par: si alguien pegara la de licencias, la puerta no lo sabria.
    preg_match("/env\\('LICENSE_PUBLIC_KEY', '([0-9a-fA-F]*)'\\)/", Repo::contents('backend/config/license.php'), $license);
    $licenseKey = $license[1] ?? '';

    expect($licenseKey === '' || ! str_contains(Repo::contents('backend/config/product.php'), $licenseKey))->toBeTrue();
})->group('RS-08', 'RL-19');

it('el abridor del fabricante carga DiagnosticsEnvelope del arbol y no lleva copia del formato', function (): void {
    $abridor = Repo::contents('tools/diagnostics-opener/src/DiagnosticsOpener.php');

    expect($abridor)->toContain('backend/app/Modules/Product/Domain/Support/DiagnosticsEnvelope.php')
        ->and($abridor)->toContain('DiagnosticsEnvelope::contents(')
        ->and($abridor)->toContain('DiagnosticsEnvelope::associatedData(')
        ->and($abridor)->toContain('DiagnosticsEnvelope::keyId(')
        ->and($abridor)->not->toContain("'kronoqr-diagnostics-sealed'")
        ->and($abridor)->not->toContain("'x25519-sealedbox+xchacha20poly1305-ietf'")
        ->and($abridor)->not->toContain("['sealed_key', 'nonce', 'ciphertext']");
})->group('RL-19', 'RF-PD-09');
