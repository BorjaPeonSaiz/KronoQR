<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\LicenseRejection;
use App\Modules\Product\Domain\ValueObject\StoredLicense;
use App\Modules\Product\Infrastructure\Adapter\Ed25519LicenseVerifier;
use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * El emisor del FABRICANTE y el verificador del PRODUCTO se entienden
 * (RF-PD-04, ADR-018).
 *
 * ## Por que esta prueba existe
 *
 * Porque son dos piezas que viven en sitios distintos y **no se despliegan
 * juntas**: `tools/license-issuer/` se queda en el repositorio del fabricante y
 * el verificador viaja en la imagen del cliente. Nada mas las ata. Si dejaran de
 * entenderse —un cambio en el formato, en la codificacion, en lo que se firma—
 * el sintoma seria una clave emitida y facturada que no activa en casa del
 * cliente, descubierta por telefono.
 *
 * ## Se ejecuta el CLI de verdad, como subproceso
 *
 * Y no se carga la clase: asi se prueba **lo que el fabricante ejecuta**,
 * incluida la lectura de la clave privada desde la variable de entorno y el
 * formato de las fechas. Ademas evita que el analizador estatico del producto
 * tenga que conocer una herramienta que, por diseño, esta fuera de su arbol.
 *
 * ## Sin ninguna clave privada en el repositorio
 *
 * El par se genera aqui, en cada ejecucion. Jamas un par fijo (§7.7, RS-08).
 */

/**
 * Ejecuta el emisor y devuelve la clave que imprime por la salida estandar.
 *
 * @param  array<string, string>  $options
 * @return array{key: string, exitCode: int, stderr: string}
 */
function issueWithFactoryTool(string $secretKeyHex, array $options = []): array
{
    $arguments = [
        '--customer=Hotel Ejemplo, S.L.',
        '--plan=estandar',
        '--max-employees=80',
        '--max-devices=3',
        '--valid-from=2026-09-01',
        '--valid-until=2027-08-31',
        '--features=advanced_reports,realtime_presence',
    ];

    foreach ($options as $name => $value) {
        $arguments[] = '--'.$name.'='.$value;
    }

    $process = new Process(
        ['php', Repo::file('tools/license-issuer/issue.php'), ...$arguments],
        env: ['KRONOQR_LICENSE_SECRET_KEY' => $secretKeyHex],
    );

    $process->run();

    return [
        'key' => trim($process->getOutput()),
        'exitCode' => $process->getExitCode() ?? -1,
        'stderr' => $process->getErrorOutput(),
    ];
}

/**
 * @return array{public: string, secret: string}
 */
function factoryKeyPair(): array
{
    $pair = sodium_crypto_sign_keypair();

    return [
        'public' => sodium_bin2hex(sodium_crypto_sign_publickey($pair)),
        'secret' => sodium_bin2hex(sodium_crypto_sign_secretkey($pair)),
    ];
}

it('una clave emitida por la herramienta del fabricante verifica en el producto', function (): void {
    $pair = factoryKeyPair();

    $issued = issueWithFactoryTool($pair['secret']);

    expect($issued['exitCode'])->toBe(0, $issued['stderr'])
        ->and($issued['key'])->toStartWith('KQL1.');

    $result = (new Ed25519LicenseVerifier($pair['public']))->verify($issued['key']);

    expect($result->isVerified())->toBeTrue()
        ->and($result->license?->customerName)->toBe('Hotel Ejemplo, S.L.')
        ->and($result->license?->plan)->toBe('estandar')
        ->and($result->license?->limits->maxEmployees)->toBe(80)
        ->and($result->license?->limits->maxDevices)->toBe(3)
        ->and($result->license?->featureNames())->toBe(['advanced_reports', 'realtime_presence'])
        // La vigencia cubre el ultimo dia ENTERO: «hasta el 31 de agosto»
        // significa el 31 de agosto completo.
        ->and($result->license?->validFrom->format('Y-m-d H:i:s'))->toBe('2026-09-01 00:00:00')
        ->and($result->license?->validUntil->format('Y-m-d H:i:s'))->toBe('2027-08-31 23:59:59');
})->group('RF-PD-04');

it('alterar un byte de la clave emitida la invalida', function (): void {
    $pair = factoryKeyPair();
    $key = issueWithFactoryTool($pair['secret'], ['max-employees' => '10'])['key'];

    [$format, $payload, $signature] = explode('.', $key);
    $payload[3] = $payload[3] === 'A' ? 'B' : 'A';

    expect((new Ed25519LicenseVerifier($pair['public']))->verify($format.'.'.$payload.'.'.$signature)->rejection)
        ->toBe(LicenseRejection::BadSignature);
})->group('RF-PD-04');

it('una clave emitida con otro par no verifica', function (): void {
    $ours = factoryKeyPair();
    $theirs = factoryKeyPair();

    $key = issueWithFactoryTool($theirs['secret'])['key'];

    expect((new Ed25519LicenseVerifier($ours['public']))->verify($key)->rejection)
        ->toBe(LicenseRejection::BadSignature);
})->group('RF-PD-04');

it('el emisor se niega a emitir sin la clave privada, y no la imprime cuando es invalida', function (): void {
    // El mensaje no repite lo recibido: seria escribir media clave privada en la
    // consola de quien se equivoco de variable.
    $sinClave = issueWithFactoryTool('');
    $conBasura = issueWithFactoryTool('esto-no-es-una-clave-privada');

    expect($sinClave['exitCode'])->toBe(1)
        ->and($sinClave['stderr'])->toContain('KRONOQR_LICENSE_SECRET_KEY')
        ->and($sinClave['key'])->toBe('')
        ->and($conBasura['key'])->toBe('')
        ->and($conBasura['stderr'])->not->toContain('esto-no-es-una-clave-privada');
})->group('RF-PD-04', 'RS-08');

it('la clave emitida sale por la salida estandar y el resumen por la de error', function (): void {
    // Para poder canalizarla a un fichero sin arrastrar el resumen. El resumen
    // lleva la huella, nunca la clave.
    $pair = factoryKeyPair();
    $issued = issueWithFactoryTool($pair['secret']);

    expect($issued['stderr'])->toContain('Hotel Ejemplo, S.L.')
        ->and($issued['stderr'])->toContain('80 personas, 3 quioscos')
        ->and($issued['stderr'])->not->toContain('KQL1.')
        // Y una sola linea en la salida estandar: la clave.
        ->and(substr_count($issued['key'], "\n"))->toBe(0);
})->group('RF-PD-04');

it('la carpeta del emisor no viaja en ninguna imagen', function (): void {
    // El `Dockerfile` de PHP hace `COPY backend/ ./`, y este directorio esta en
    // la raiz del repositorio. Si alguien lo moviera dentro de `backend/`, la
    // clave privada del fabricante pasaria a tener un sitio donde acabar
    // desplegada (§7.7, RS-08).
    expect(is_dir(Repo::file('tools/license-issuer')))->toBeTrue()
        ->and(is_dir(Repo::file('backend/tools/license-issuer')))->toBeFalse();

    $dockerfile = Repo::contents('infra/docker/php/Dockerfile');

    expect($dockerfile)->not->toContain('COPY tools/')
        ->and($dockerfile)->not->toContain('license-issuer');
})->group('RF-PD-04');

it('en el repositorio no hay ninguna clave privada de emision', function (): void {
    // El control que impide el error mas caro de esta tarea. Se busca por
    // contenido y no por nombre de fichero: lo peligroso es una constante
    // hexadecimal de 128 caracteres pegada «solo para probar».
    $offenders = [];
    $files = [
        'backend/config/license.php',
        '.env.example',
        'tools/license-issuer/src/LicenseIssuer.php',
        'tools/license-issuer/issue.php',
        'tools/license-issuer/generate-keypair.php',
        'tools/license-issuer/README.md',
    ];

    foreach ($files as $file) {
        if (! is_file(Repo::file($file))) {
            continue;
        }

        if (preg_match('/\b[0-9a-fA-F]{128}\b/', Repo::contents($file)) === 1) {
            $offenders[] = $file;
        }
    }

    expect($offenders)->toBe(
        [],
        'Una cadena hexadecimal de 128 caracteres tiene la forma exacta de una clave privada ed25519. '
        .'La de emision se custodia FUERA del repositorio (§7.7, RS-08). Ficheros: '.implode(', ', $offenders)
    );
})->group('RF-PD-04', 'RS-08');

it('la huella que imprime el emisor es la que enseña license:show tras activar', function (): void {
    // Es el numero por el que el fabricante y el hotel se entienden por
    // telefono: «la clave que activaste, ¿empieza por b0de...?». Si el emisor y
    // el producto calcularan huellas distintas, esa conversacion no llevaria a
    // ninguna parte, y son dos piezas que **no se despliegan juntas**.
    $pair = factoryKeyPair();
    $issued = issueWithFactoryTool($pair['secret']);

    expect($issued['exitCode'])->toBe(0, $issued['stderr']);

    // La que imprime el emisor por la salida de error, en su resumen.
    $encontrada = preg_match('/Huella:\s+([0-9a-f]{12})/', $issued['stderr'], $delEmisor);

    expect($encontrada)->toBe(1, 'El emisor ha dejado de imprimir la huella en su resumen.');

    // Y se lee del array solo cuando consta que hay captura: PHPStan no deduce
    // que un `preg_match` con resultado 1 llene el grupo, y tiene razon en el
    // caso general.
    $huella = $delEmisor[1] ?? '';

    // Y la que calcula el producto sobre la misma clave.
    expect(StoredLicense::fingerprintOf($issued['key']))->toBe($huella);
})->group('RF-PD-04');

it('el emisor se niega a firmar lo que el producto rechazaria', function (array $options, string $fragmento): void {
    // Sin esto, el emisor firma una clave que el cliente activa y su producto
    // rechaza como `invalid_payload` o que verifica y no concede nada. El fallo
    // se descubre en casa del hotel, con la factura ya mandada.
    $pair = factoryKeyPair();
    $issued = issueWithFactoryTool($pair['secret'], $options);

    expect($issued['exitCode'])->toBe(1)
        ->and($issued['key'])->toBe('')
        ->and($issued['stderr'])->toContain($fragmento);
})->with([
    // `(int) 'ochenta'` es 0, y el esquema exige positivos.
    'limite no numerico' => [['max-employees' => 'ochenta'], 'numero entero mayor que cero'],
    'limite cero' => [['max-employees' => '0'], 'numero entero mayor que cero'],
    'limite negativo' => [['max-devices' => '-3'], 'numero entero mayor que cero'],
    // El producto rechaza esta clave al activarla.
    'vigencia invertida' => [['valid-until' => '2025-01-01'], 'termina antes de empezar'],
    // Verifica y no concede NADA: el cliente activa y ve sus informes apagados.
    'funcionalidad con errata' => [['features' => 'advanced_report'], 'no existen en el catalogo'],
    'funcionalidad inventada' => [['features' => 'todo'], 'no existen en el catalogo'],
])->group('RF-PD-04');

it('el emisor deja emitir una funcionalidad futura con --force', function (): void {
    // El caso legitimo: el producto va por delante de esta herramienta. La
    // instalacion que no la conozca la ignorara al leer la clave, y la que si,
    // la aplicara.
    $pair = factoryKeyPair();
    $issued = issueWithFactoryTool($pair['secret'], [
        'features' => 'advanced_reports,algo_que_llega_en_la_2_0',
        'force' => '1',
    ]);

    expect($issued['exitCode'])->toBe(0, $issued['stderr']);

    $result = (new Ed25519LicenseVerifier($pair['public']))->verify($issued['key']);

    // El producto descarta lo que no conoce y concede lo que si.
    expect($result->isVerified())->toBeTrue()
        ->and($result->license?->featureNames())->toBe(['advanced_reports']);
})->group('RF-PD-04');
