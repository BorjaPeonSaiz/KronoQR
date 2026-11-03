<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Exception;

use RuntimeException;

/**
 * Se pidio el paquete con datos personales y la instalacion no tiene una clave
 * publica de soporte valida con la que cifrarlo (**RL-19**; ADR-020, enmienda
 * del 01-11-2026).
 *
 * Se lanza **antes de recoger nada**: no queda ni el paquete en memoria ni
 * ningun asiento. Por HTTP es `503` con `type`
 * `urn:kronoqr:problem:diagnostics-sealing-unavailable` y sin `Retry-After`,
 * porque esperar no lo arregla; por consola, el codigo `1` y un mensaje que
 * remite al paquete anonimizado, que no depende de esta clave.
 *
 * El mensaje no lleva ningun valor de la clave: dice que falta, no cual es.
 */
final class DiagnosticsSealingUnavailable extends RuntimeException
{
    public static function missingKey(): self
    {
        return new self('This installation has no valid support public key, so the bundle with personal data cannot be encrypted.');
    }
}
