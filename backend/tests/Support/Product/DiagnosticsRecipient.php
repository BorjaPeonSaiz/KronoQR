<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use App\Modules\Product\Infrastructure\Diagnostics\SodiumDiagnosticsBundleSealer;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/**
 * Un par X25519 de soporte **generado en cada prueba**, instalado como la clave
 * publica de la instalacion, y el abridor del fabricante para leer lo que se
 * cifro con el (ADR-020, enmienda del 01-11-2026; RS-08).
 *
 * Nunca un par fijo del repositorio: la privada de soporte no existe en ningun
 * fichero, tampoco en las pruebas.
 *
 * ## El abridor se ejecuta de verdad, como subproceso
 *
 * `tools/diagnostics-opener/open.php`, con su llavero en un directorio
 * temporal: es lo que ejecuta el fabricante, y si el producto y el abridor
 * dejaran de entenderse estas pruebas fallarian antes que un cliente. Como
 * `LicenseIssuerRoundTripTest` con el emisor de licencias, evita ademas que el
 * analizador estatico del producto tenga que conocer una herramienta que, por
 * diseño, esta fuera de su arbol.
 */
final readonly class DiagnosticsRecipient
{
    private function __construct(
        public string $publicKeyHex,
        public string $secretKeyHex,
        public string $keyId,
    ) {}

    /** Un par nuevo, sin instalarlo. */
    public static function generate(): self
    {
        $pair = sodium_crypto_box_keypair();
        $public = sodium_crypto_box_publickey($pair);

        return new self(
            bin2hex($public),
            bin2hex(sodium_crypto_box_secretkey($pair)),
            SodiumDiagnosticsBundleSealer::keyIdOf($public),
        );
    }

    /** Un par nuevo, instalado como la clave publica de soporte de la instalacion. */
    public static function install(): self
    {
        $recipient = self::generate();
        config(['product.diagnostics_recipient_public_key' => $recipient->publicKeyHex]);

        return $recipient;
    }

    /** La instalacion sin clave publica de soporte: el valor de serie vaciado a proposito. */
    public static function uninstall(): void
    {
        config(['product.diagnostics_recipient_public_key' => '']);
    }

    /**
     * Ejecuta el abridor sobre un sobre con la privada de este par.
     *
     * @return array{exit: int, stdout: string, stderr: string, plaintext: string|null}
     */
    public function run(string $sealed): array
    {
        $work = sys_get_temp_dir().'/kq-opener-'.bin2hex(random_bytes(6));
        mkdir($work.'/llavero', 0o700, true);
        file_put_contents($work.'/llavero/'.$this->keyId.'.key', $this->secretKeyHex."\n");
        file_put_contents($work.'/paquete.sealed.json', $sealed);

        try {
            $process = new Process([
                'php',
                Repo::file('tools/diagnostics-opener/open.php'),
                $work.'/paquete.sealed.json',
                '--keyring='.$work.'/llavero',
            ]);
            $process->run();

            $output = $work.'/paquete.json';

            return [
                'exit' => $process->getExitCode() ?? -1,
                'stdout' => $process->getOutput(),
                'stderr' => $process->getErrorOutput(),
                'plaintext' => is_file($output) ? (string) file_get_contents($output) : null,
            ];
        } finally {
            foreach ([...(glob($work.'/*') ?: []), ...(glob($work.'/llavero/*') ?: [])] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            @rmdir($work.'/llavero');
            @rmdir($work);
        }
    }

    /**
     * El paquete en claro de un sobre, decodificado. Lanza si el abridor no lo
     * abre.
     *
     * @return array<string, mixed>
     */
    public function openedDocument(string $sealed): array
    {
        $result = $this->run($sealed);
        $document = $result['plaintext'] === null ? null : json_decode($result['plaintext'], true);

        if ($result['exit'] !== 0 || ! \is_array($document)) {
            throw new RuntimeException('El abridor no abrio el sobre (salida '.$result['exit'].'): '.$result['stderr']);
        }

        /** @var array<string, mixed> $document */
        return $document;
    }
}
