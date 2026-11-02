<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics;

use App\Modules\Product\Application\Port\DiagnosticsBundleSealer;
use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use App\Modules\Product\Domain\ValueObject\SealedDiagnosticsBundle;
use LogicException;

/**
 * El sobre del paquete con datos personales, con libsodium (**RL-19**;
 * ADR-020, enmienda del 01-11-2026).
 *
 * ## Lo que hace, en orden
 *
 * 1. Una clave aleatoria de un solo uso, `K`, y un `nonce` de 24 bytes.
 * 2. El paquete en claro —el mismo texto que se escribiria sin cifrar— se
 *    cifra con XChaCha20-Poly1305-IETF bajo `K`, con la cabecera en forma
 *    canonica como datos asociados.
 * 3. `K` va en un sobre sellado (`crypto_box_seal`) para la clave publica de
 *    soporte, y se borra de memoria.
 *
 * Es el mismo primitivo del PIN sellado del quiosco
 * (`SodiumSealedPinOpener`), al reves: aqui la instalacion solo tiene la
 * publica.
 *
 * ## Una clave propia, no la de licencias
 *
 * `product.diagnostics_recipient_public_key` y no `license.public_key`: con la
 * privada de soporte se abren paquetes y con la de licencias se emiten
 * licencias, y separarlas evita que el equipo de soporte pueda emitir
 * licencias. Rotan con ciclos distintos y el compromiso de una no arrastra a la
 * otra. Las dos se compilan con la imagen de cada version publicada.
 *
 * ## Una clave que no vale es una clave que no hay
 *
 * Hexadecimal de 64 caracteres o nada: con cualquier otra cosa `available()`
 * dice que no, y el paquete con datos personales no se genera. Nunca se cae al
 * texto en claro.
 */
final readonly class SodiumDiagnosticsBundleSealer implements DiagnosticsBundleSealer
{
    /** Longitud del identificador de clave, en hexadecimales. */
    public const int KEY_ID_LENGTH = 16;

    private ?string $publicKey;

    public function __construct(string $publicKeyHex)
    {
        $this->publicKey = self::decode($publicKeyHex);
    }

    public function available(): bool
    {
        return $this->publicKey !== null;
    }

    public function recipientKeyId(): ?string
    {
        return $this->publicKey === null ? null : self::keyIdOf($this->publicKey);
    }

    public function seal(DiagnosticsBundle $bundle, int $periodDays): SealedDiagnosticsBundle
    {
        if ($this->publicKey === null) {
            throw new LogicException('No hay clave publica de soporte: el paquete con datos personales no se puede sellar.');
        }

        if ($bundle->manifest->anonymized) {
            throw new LogicException('El paquete anonimizado sale en claro: no se sella.');
        }

        $plaintext = $bundle->toPlaintextJson();
        $keyId = self::keyIdOf($this->publicKey);
        $contents = SealedDiagnosticsBundle::contentsOf($bundle, $periodDays, \strlen($plaintext));
        $associated = SealedDiagnosticsBundle::associatedData(
            SealedDiagnosticsBundle::header($bundle->manifest, $keyId, $contents),
        );

        $key = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $associated, $nonce, $key);
            $sealedKey = sodium_crypto_box_seal($key, $this->publicKey);
        } finally {
            sodium_memzero($key);
        }

        return new SealedDiagnosticsBundle(
            manifest: $bundle->manifest,
            recipientKeyId: $keyId,
            contents: $contents,
            sealedKey: base64_encode($sealedKey),
            nonce: base64_encode($nonce),
            ciphertext: base64_encode($ciphertext),
        );
    }

    /** Los 16 primeros hexadecimales del SHA-256 de la clave publica en bruto. */
    public static function keyIdOf(string $publicKey): string
    {
        return substr(hash('sha256', $publicKey), 0, self::KEY_ID_LENGTH);
    }

    private static function decode(string $hex): ?string
    {
        $hex = trim($hex);

        if (preg_match('/^[0-9a-fA-F]{'.(SODIUM_CRYPTO_BOX_PUBLICKEYBYTES * 2).'}$/D', $hex) !== 1) {
            return null;
        }

        $key = hex2bin($hex);

        // Una clave de ceros no es una clave: es un marcador que alguien olvido
        // sustituir, y no corresponde a ninguna privada del fabricante.
        return $key === false || $key === str_repeat("\0", SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) ? null : $key;
    }
}
