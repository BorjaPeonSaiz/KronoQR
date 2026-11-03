<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DiagnosticsBundleSealer;
use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;

/**
 * Sonda `diagnostics.recipient_key` de `product:doctor`: si la instalacion
 * puede cifrar el paquete de diagnostico con datos personales (RF-PD-13,
 * RL-19; ADR-020, enmienda del 01-11-2026).
 *
 * - `ok`, con el identificador corto de la clave (`key_id`): el mismo que
 *   lleva el sobre en `recipient_key_id`, para que soporte sepa con que
 *   privada abrirlo.
 * - `warning` si no hay clave valida: el paquete con datos personales no se
 *   genera. **Aviso y nunca fallo**: fichar, el registro y el paquete
 *   anonimizado no dependen de esto, y un `2` aqui abortaria una
 *   actualizacion por algo que no impide trabajar.
 */
final readonly class DiagnosticsRecipientKeyProbe implements DoctorProbe
{
    public const string ID = 'diagnostics.recipient_key';

    public function __construct(private DiagnosticsBundleSealer $sealer) {}

    public function family(): string
    {
        return 'diagnostics';
    }

    public function run(): array
    {
        $keyId = $this->sealer->recipientKeyId();

        if ($keyId === null) {
            return [DoctorFinding::warning(self::ID, details: ['configured' => false])];
        }

        return [DoctorFinding::ok(self::ID, ['configured' => true, 'key_id' => $keyId], ['key_id' => $keyId])];
    }
}
