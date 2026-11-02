<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use App\Modules\Product\Application\Port\DiagnosticsBundleSealer;
use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use App\Modules\Product\Domain\ValueObject\SealedDiagnosticsBundle;

/**
 * Doble del sellador para las pruebas del caso de uso, sin criptografia: cuenta
 * las llamadas y devuelve un sobre con la cabecera de verdad y un «cifrado» que
 * es el texto en claro en base64. Lo que se prueba con el es el orden y las
 * decisiones del caso de uso; el cifrado de verdad lo prueban las de
 * integracion con `SodiumDiagnosticsBundleSealer`.
 */
final class RecordingDiagnosticsSealer implements DiagnosticsBundleSealer
{
    public const string KEY_ID = '0123456789abcdef';

    public int $sealed = 0;

    public int $asked = 0;

    public function __construct(private readonly bool $available = true) {}

    public function available(): bool
    {
        $this->asked++;

        return $this->available;
    }

    public function recipientKeyId(): ?string
    {
        return $this->available ? self::KEY_ID : null;
    }

    public function seal(DiagnosticsBundle $bundle, int $periodDays): SealedDiagnosticsBundle
    {
        $this->sealed++;
        $plaintext = $bundle->toPlaintextJson();

        return new SealedDiagnosticsBundle(
            manifest: $bundle->manifest,
            recipientKeyId: self::KEY_ID,
            contents: SealedDiagnosticsBundle::contentsOf($bundle, $periodDays, \strlen($plaintext)),
            sealedKey: base64_encode('clave'),
            nonce: base64_encode(str_repeat("\0", 24)),
            ciphertext: base64_encode($plaintext),
        );
    }
}
